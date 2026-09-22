<?php

namespace Tests\Feature\Filament;

use App\Enums\CiotStatusEnum;
use App\Filament\Resources\CiotResource;
use App\Filament\Resources\CiotResource\Pages\CreateCiot;
use App\Filament\Resources\CiotResource\Pages\ListCiots;
use App\Filament\Resources\CiotResource\Pages\ViewCiot;
use App\Jobs\EmitCiotJob;
use App\Models\Ciot;
use App\Models\CiotPayer;
use App\Models\CiotVehicle;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CiotResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.lines.vehicle_removal' => [
                'bank_code' => '756',
                'bank_agency' => '0001',
                'bank_account' => '111',
            ],
        ]);
    }

    public function test_the_list_shows_ciots_with_badges(): void
    {
        $open = Ciot::factory()->issued()->create(['payer_name' => 'Copart Caçapava']);
        $draft = Ciot::factory()->create(['payer_name' => 'Copart Osasco']);

        Livewire::test(ListCiots::class)
            ->assertCanSeeTableRecords([$open, $draft])
            ->assertSee('Emitido');
    }

    public function test_the_create_form_builds_a_draft_ciot_snapshot(): void
    {
        $payer = CiotPayer::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $delivery = CiotPayer::factory()->create(['cnpj' => '14517191000410', 'name' => 'Copart Osasco']);
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);
        $trailer = CiotVehicle::factory()->trailer()->create(['plate' => 'TIX7D32', 'axles' => 2]);

        Livewire::test(CreateCiot::class)
            ->fillForm([
                'line' => 'vehicle_removal',
                'operation_type' => 'lotation',
                'payer_id' => $payer->id,
                'delivery_payer_id' => $delivery->id,
                'origin.cidade' => 'Osvaldo Cruz',
                'origin.uf' => 'SP',
                'origin.cep' => '17700000',
                'origin.ibge' => '3534609',
                'destination.cidade' => 'Caçapava',
                'destination.uf' => 'SP',
                'destination.cep' => '12286140',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '1000.00',
                'cargo_weight_kg' => '20000',
                'vehicle_ids' => [$tractor->id, $trailer->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ciot = Ciot::query()->firstOrFail();

        $this->assertSame(CiotStatusEnum::DRAFT, $ciot->status);
        $this->assertSame('14517191000925', $ciot->payer_cnpj);
        $this->assertSame('Copart Caçapava', $ciot->payer_name);
        $this->assertSame('14517191000410', $ciot->delivery_payer_cnpj);
        $this->assertSame(100000, $ciot->freight_value_cents);
        $this->assertCount(2, $ciot->vehicles);
        $this->assertSame('PUC8E55', $ciot->vehicles[0]['placa']);
        $this->assertNotNull($ciot->public_id);
    }

    public function test_the_emit_action_enqueues_the_emission(): void
    {
        Queue::fake();

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => '560000569998'],
            ], 200),
        ]);

        $ciot = Ciot::factory()->create();

        Livewire::test(ListCiots::class)
            ->callTableAction('emit', $ciot);

        $this->assertSame(CiotStatusEnum::PENDING, $ciot->refresh()->status);
        Queue::assertPushed(EmitCiotJob::class);
    }

    public function test_the_cancel_action_requires_and_records_a_reason(): void
    {
        $ciot = Ciot::factory()->issued()->create();

        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/CancelamentoOperacaoTransporte' => Http::response([
                'CodigoIdentificacaoOperacao' => $ciot->fullNumber(),
                'Codigo' => '110',
                'Mensagem' => 'Cancelado',
                'DataCancelamento' => '2026-09-22T10:00:00-03:00',
            ], 200),
        ]);

        Livewire::test(ListCiots::class)
            ->callTableAction('cancel', $ciot, data: ['motivo' => 'Carga não ocorreu'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(CiotStatusEnum::CANCELED, $ciot->refresh()->status);
        $this->assertSame('Carga não ocorreu', $ciot->cancel_reason);
    }

    public function test_the_view_page_shows_the_number_and_carrier_notice(): void
    {
        $ciot = Ciot::factory()->issued()->create([
            'verifier_code' => '1234',
            'carrier_notice' => 'Aviso importante ao transportador',
        ]);

        Livewire::test(ViewCiot::class, ['record' => $ciot->id])
            ->assertSee($ciot->fullNumber())
            ->assertSee('Aviso importante ao transportador');
    }

    public function test_the_navigation_badge_counts_open_ciots(): void
    {
        Ciot::factory()->issued()->create();
        Ciot::factory()->issued()->create();
        Ciot::factory()->create();

        $this->assertSame('2', CiotResource::getNavigationBadge());
    }
}

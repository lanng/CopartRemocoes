<?php

namespace Tests\Feature\Filament;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Filament\Resources\CteEmissionBatchResource;
use App\Filament\Resources\CteEmissionBatchResource\Pages\ListCteEmissionBatches;
use App\Filament\Resources\CteEmissionBatchResource\Pages\ViewCteEmissionBatch;
use App\Filament\Resources\CteEmissionBatchResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\RegisterResource;
use App\Models\Ciot;
use App\Models\CiotPayer;
use App\Models\CiotVehicle;
use App\Models\City;
use App\Models\CityDistance;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Models\Register;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CteEmissionBatchResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ciot.env' => 'homologacao',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.gerar_base_url' => null,
            'ciot.api_key' => 'test-api-key',
            'ciot.natureza_fallback' => false,
            'ciot.lines.vehicle_removal' => [
                'bank_code' => '756',
                'bank_agency' => '0001',
                'bank_account' => '111',
            ],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    public function test_the_batch_view_renders_the_mdfe_section_with_a_document(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);

        MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'mdfe_number' => null,
            'protocol' => null,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertSee('MDF-e da viagem')
            ->assertSee('sem número');
    }

    public function test_generate_ciot_action_creates_and_emits_a_linked_ciot(): void
    {
        config(['ciot.removal.weight_per_vehicle_kg' => 2000]);

        City::factory()->create([
            'ibge_code' => '3534609', 'name' => 'Osvaldo Cruz', 'state' => 'SP',
            'latitude' => -21.7972, 'longitude' => -50.9736,
        ]);
        City::factory()->create([
            'ibge_code' => '3508504', 'name' => 'Caçapava', 'state' => 'SP',
            'latitude' => -23.1006, 'longitude' => -45.6911,
        ]);
        $payer = CiotPayer::factory()->create([
            'name' => 'Copart Caçapava', 'cnpj' => '14517191000925',
            'city' => 'Caçapava', 'state' => 'SP', 'ibge_code' => '3508504',
        ]);
        $tractor = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        CityDistance::query()->create([
            'origin_ibge' => '3534609', 'destination_ibge' => '3508504',
            'km' => 716, 'fetched_at' => now(),
        ]);

        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'company' => 'copart', 'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz', 'destination_city' => 'Caçapava',
                'value' => '500.00', 'fipe_value' => '10000.00',
            ],
        ]);

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true, 'Dados' => ['CIOT' => '520032951111'],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados cadastrados com sucesso',
                'Protocolo' => '5200329511110001',
                'CodigoVerificador' => '0001',
                'IdOperacaoTransporte' => '520032951111',
            ], 200),
        ]);

        Queue::fake();

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->callAction('generateCiot', data: [
                'operation_type' => 'lotation',
                'payer_id' => $payer->id,
                'delivery_payer_id' => $payer->id,
                'origin.ibge' => '3534609',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '500.00',
                'cargo_weight_kg' => '2000',
                'vehicle_ids' => [$tractor->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->assertHasNoActionErrors();

        $ciot = Ciot::query()->where('cte_emission_batch_id', $batch->id)->firstOrFail();

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
        $this->assertSame('520032951111', $ciot->ciot_number);
        $this->assertSame('14517191000925', $ciot->payer_cnpj);

        Queue::assertNothingPushed();
    }

    public function test_generate_ciot_action_sends_additional_payers_cnpjs_to_the_antt(): void
    {
        City::factory()->create([
            'ibge_code' => '3534609', 'name' => 'Osvaldo Cruz', 'state' => 'SP',
            'latitude' => -21.7972, 'longitude' => -50.9736,
        ]);
        City::factory()->create([
            'ibge_code' => '3508504', 'name' => 'Caçapava', 'state' => 'SP',
            'latitude' => -23.1006, 'longitude' => -45.6911,
        ]);
        $payer = CiotPayer::factory()->create([
            'name' => 'Copart Caçapava', 'cnpj' => '14517191000925',
            'city' => 'Caçapava', 'state' => 'SP', 'ibge_code' => '3508504',
        ]);
        CiotPayer::factory()->create([
            'name' => 'Copart Pirapora do Bom Jesus', 'cnpj' => '14517191000330',
            'city' => 'Pirapora do Bom Jesus', 'state' => 'SP', 'ibge_code' => '3539506',
        ]);
        CiotPayer::factory()->create([
            'name' => 'Copart Osasco', 'cnpj' => '14517191000410',
            'city' => 'Osasco', 'state' => 'SP', 'ibge_code' => '3518800',
        ]);
        $tractor = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        CityDistance::query()->create([
            'origin_ibge' => '3534609', 'destination_ibge' => '3508504',
            'km' => 716, 'fetched_at' => now(),
        ]);

        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'company' => 'copart', 'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz', 'destination_city' => 'Caçapava',
                'value' => '500.00', 'fipe_value' => '10000.00',
            ],
        ]);

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true, 'Dados' => ['CIOT' => '520032952222'],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados cadastrados com sucesso',
                'Protocolo' => '5200329522220001',
                'CodigoVerificador' => '0001',
                'IdOperacaoTransporte' => '520032952222',
            ], 200),
        ]);

        Queue::fake();

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->callAction('generateCiot', data: [
                'operation_type' => 'fractioned',
                'payer_id' => $payer->id,
                'delivery_payer_id' => $payer->id,
                'additional_payers' => ['14517191000330', '14517191000410'],
                'origin.ibge' => '3534609',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '500.00',
                'cargo_weight_kg' => '2000',
                'vehicle_ids' => [$tractor->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->assertHasNoActionErrors();

        $ciot = Ciot::query()->where('cte_emission_batch_id', $batch->id)->firstOrFail();

        $this->assertSame(['14517191000330', '14517191000410'], $ciot->additional_payers);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            if (! str_contains($request->url(), 'DeclaracaoOperacaoTransporte')) {
                return false;
            }

            return ($request->data()['DadosCarga'] ?? [])['ContratantesCargFrac'] === [
                '14517191000330',
                '14517191000410',
            ];
        });
    }

    public function test_generate_ciot_action_rejects_the_payer_repeated_as_additional_b119(): void
    {
        City::factory()->create([
            'ibge_code' => '3534609', 'name' => 'Osvaldo Cruz', 'state' => 'SP',
            'latitude' => -21.7972, 'longitude' => -50.9736,
        ]);
        City::factory()->create([
            'ibge_code' => '3508504', 'name' => 'Caçapava', 'state' => 'SP',
            'latitude' => -23.1006, 'longitude' => -45.6911,
        ]);
        $payer = CiotPayer::factory()->create([
            'name' => 'Copart Caçapava', 'cnpj' => '14517191000925',
            'city' => 'Caçapava', 'state' => 'SP', 'ibge_code' => '3508504',
        ]);
        CiotPayer::factory()->create([
            'name' => 'Copart Osasco', 'cnpj' => '14517191000410',
            'city' => 'Osasco', 'state' => 'SP', 'ibge_code' => '3518800',
        ]);
        $tractor = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        CityDistance::query()->create([
            'origin_ibge' => '3534609', 'destination_ibge' => '3508504',
            'km' => 716, 'fetched_at' => now(),
        ]);

        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'company' => 'copart', 'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz', 'destination_city' => 'Caçapava',
                'value' => '500.00', 'fipe_value' => '10000.00',
            ],
        ]);

        Queue::fake();

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->callAction('generateCiot', data: [
                'operation_type' => 'fractioned',
                'payer_id' => $payer->id,
                'delivery_payer_id' => $payer->id,
                'additional_payers' => ['14517191000925', '14517191000410'],
                'origin.ibge' => '3534609',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '500.00',
                'cargo_weight_kg' => '2000',
                'vehicle_ids' => [$tractor->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->assertHasActionErrors();

        $this->assertSame(0, Ciot::query()->where('cte_emission_batch_id', $batch->id)->count());
    }

    public function test_reemit_ciot_action_reissues_a_failed_ciot(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'execution_mode' => 'live',
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'access_key' => '35260912563112000130570010000028171839975374',
            'snapshot' => [
                'company' => 'copart', 'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz', 'destination_city' => 'Caçapava',
                'value' => '500.00', 'fipe_value' => '10000.00',
            ],
        ]);
        $ciot = Ciot::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CiotStatusEnum::FAILED,
            'error_message' => 'Rejeição anterior',
            'id_operacao_transporte' => '520032959998',
        ]);

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true, 'Dados' => ['CIOT' => '520032952222'],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados cadastrados com sucesso',
                'Protocolo' => '5200329522220002',
                'CodigoVerificador' => '0002',
                'IdOperacaoTransporte' => '520032952222',
            ], 200),
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->callAction('reemitCiot')
            ->assertNotified('CIOT reemitido: 5200329522220002');

        $ciot->refresh();

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
        $this->assertSame('520032952222', $ciot->ciot_number);
        $this->assertSame('0002', $ciot->verifier_code);
        $this->assertNotSame('520032959998', $ciot->id_operacao_transporte);
    }

    public function test_generate_ciot_action_blocks_duplicates(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'company' => 'copart', 'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz', 'destination_city' => 'Caçapava',
                'value' => '500.00', 'fipe_value' => '10000.00',
            ],
        ]);
        Ciot::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CiotStatusEnum::ISSUED,
        ]);

        $count = Ciot::query()->where('cte_emission_batch_id', $batch->id)->count();

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->callAction('generateCiot', data: [
                'operation_type' => 'lotation',
                'payer_id' => 1,
                'delivery_payer_id' => 1,
                'origin.ibge' => '3534609',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '500.00',
                'cargo_weight_kg' => '2000',
                'vehicle_ids' => [1],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->assertNotified('Este lote já possui um CIOT ativo');

        $this->assertSame($count, Ciot::query()->where('cte_emission_batch_id', $batch->id)->count());
    }

    public function test_the_batch_list_uses_translated_labels_and_brasilia_dates(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
            'execution_mode' => 'dry_run',
            'approved_at' => Carbon::create(2026, 8, 12, 15, 30, 'UTC'),
            'created_at' => Carbon::create(2026, 8, 12, 16, 30, 'UTC'),
        ]);

        $component = Livewire::test(ListCteEmissionBatches::class)
            ->assertCanSeeTableRecords([$batch])
            ->assertTableColumnFormattedStateSet('status', 'Concluído com erros', $batch)
            ->assertTableColumnFormattedStateSet('execution_mode', 'Simulação', $batch)
            ->assertTableColumnFormattedStateSet('approved_at', '12/08/2026 12:30', $batch)
            ->assertTableColumnFormattedStateSet('created_at', '12/08/2026 13:30', $batch)
            ->assertTableColumnExists('status', fn ($column): bool => $column->getLabel() === 'Situação')
            ->assertTableColumnExists('execution_mode', fn ($column): bool => $column->getLabel() === 'Modo')
            ->assertTableColumnExists('approved_at', fn ($column): bool => $column->getLabel() === 'Aprovado em')
            ->assertTableColumnExists('created_at', fn ($column): bool => $column->getLabel() === 'Criado em')
            ->assertTableColumnExists('documents_count', fn ($column): bool => $column->getLabel() === 'Itens')
            ->assertTableActionHasLabel('view', 'Ver lote', $batch)
            ->assertTableHeaderActionsExistInOrder([]);

        $this->assertSame(
            CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS->label(),
            $component->instance()->getTable()->getFilter('status')->getOptions()[CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS->value],
        );
        $this->assertSame('Situação', $component->instance()->getTable()->getFilter('status')->getLabel());
        $this->assertSame('Modo', $component->instance()->getTable()->getFilter('execution_mode')->getLabel());
        $this->assertSame([
            'dry_run' => 'Simulação',
            'live' => 'Emissão real',
        ], $component->instance()->getTable()->getFilter('execution_mode')->getOptions());
    }

    public function test_the_batch_detail_uses_translated_labels_and_actions(): void
    {
        $creator = User::factory()->create(['name' => 'Criador do lote']);
        $approver = User::factory()->create(['name' => 'Aprovador do lote']);
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
            'execution_mode' => 'live',
            'created_by' => $creator->id,
            'approved_by' => $approver->id,
            'approved_at' => Carbon::create(2026, 8, 12, 15, 30, 'UTC'),
            'processing_started_at' => Carbon::create(2026, 8, 12, 16, 30, 'UTC'),
            'completed_at' => Carbon::create(2026, 8, 12, 17, 30, 'UTC'),
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'fipe_value' => '43897.00',
                'value' => '1250.00',
            ],
        ]);
        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSee('Valor total da carga')
            ->assertSee('43.897,00')
            ->assertSee('Valor total do transporte')
            ->assertSee('1.250,00')
            ->assertSee('Situação')
            ->assertSee('Rascunho')
            ->assertSee('Modo')
            ->assertSee('Emissão real')
            ->assertSee('Criado por')
            ->assertSee('Criador do lote')
            ->assertSee('Aprovado por')
            ->assertSee('Aprovador do lote')
            ->assertSee('Aprovado em')
            ->assertSee('12/08/2026 12:30')
            ->assertSee('Processamento iniciado em')
            ->assertSee('12/08/2026 13:30')
            ->assertSee('Concluído em')
            ->assertSee('12/08/2026 14:30')
            ->assertActionHasLabel('delete', 'Excluir lote')
            ->assertActionHasLabel('approve', 'Aprovar lote');
    }

    public function test_the_batch_detail_polls_for_batch_updates(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::PROCESSING,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSee('wire:poll.5s="refreshBatch"', false);
    }

    public function test_refresh_batch_reloads_the_latest_batch_status(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::PROCESSING,
        ]);
        $component = Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->getRouteKey()]);

        $batch->update([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
            'completed_at' => now(),
        ]);

        $component
            ->call('refreshBatch')
            ->assertSee('Concluído com erros');
    }

    public function test_the_delete_action_is_only_available_for_draft_batches(): void
    {
        $draft = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $approved = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);

        Livewire::test(ListCteEmissionBatches::class)
            ->assertTableActionHasLabel('delete', 'Excluir lote', $draft)
            ->assertTableActionHidden('delete', $approved);
    }

    public function test_a_draft_batch_can_be_deleted_from_the_batch_list(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
        ]);

        Livewire::test(ListCteEmissionBatches::class)
            ->callTableAction('delete', $batch);

        $this->assertModelMissing($batch);
        $this->assertModelMissing($document);
    }

    public function test_a_draft_batch_can_be_deleted_from_the_batch_detail(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->getRouteKey()])
            ->callAction('delete')
            ->assertRedirect(CteEmissionBatchResource::getUrl('index'));

        $this->assertModelMissing($batch);
        $this->assertModelMissing($document);
    }

    public function test_documents_show_translated_state_and_the_register_action(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        ]);
        $reconciliationDocument = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);

        $component = Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertCanSeeTableRecords([$document])
            ->assertTableColumnFormattedStateSet('status', 'Falha antes da autorização', $document)
            ->assertTableColumnExists('cte_number', fn ($column): bool => $column->getLabel() === 'Número do CT-e')
            ->assertTableColumnExists('authorized_at', fn ($column): bool => $column->getLabel() === 'Autorizado em')
            ->assertTableActionHasLabel('retry', 'Tentar novamente', $document)
            ->assertTableActionHasLabel('reconcile', 'Conciliar', $reconciliationDocument)
            ->assertTableActionHasLabel('viewRegister', 'Ver registro', $document);

        $this->assertArrayHasKey('register', $component->instance()->getTable()->getQuery()->getEagerLoads());
        $this->assertSame(25, $component->instance()->getTable()->getDefaultPaginationPageOption());
    }

    public function test_the_documents_table_polls_for_document_updates(): void
    {
        $batch = CteEmissionBatch::factory()->create();

        $component = Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ]);

        $this->assertSame('5s', $component->instance()->getTable()->getPollingInterval());
    }

    public function test_a_draft_document_can_be_removed_from_a_draft_batch(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertTableActionHasLabel('remove', 'Retirar do lote', $document)
            ->callTableAction('remove', $document);

        $this->assertModelMissing($document);
        $this->assertModelMissing($batch);
        $this->assertModelExists($document->register);
    }

    public function test_failed_documents_can_be_retried_in_bulk_and_reopen_the_batch(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
        ]);
        $failed = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        ]);
        $authorized = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertTableBulkActionHasLabel('retrySelected', 'Tentar novamente selecionados')
            ->callTableBulkAction('retrySelected', [$failed, $authorized]);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $failed->refresh()->status);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->refresh()->status);
        $this->assertSame(CteEmissionBatchStatusEnum::PROCESSING, $batch->refresh()->status);
    }

    public function test_a_document_stuck_in_filling_can_be_retried_from_the_documents_table(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
        ]);
        $stuck = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FILLING,
            'claimed_at' => now(),
            'claim_expires_at' => now()->addMinutes(10),
        ]);
        $authorized = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '2707',
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertTableActionVisible('retry', $stuck)
            ->assertTableActionHidden('retry', $authorized)
            ->callTableAction('retry', $stuck);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $stuck->refresh()->status);
        $this->assertNull($stuck->claim_expires_at);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->refresh()->status);
        $this->assertSame(CteEmissionBatchStatusEnum::PROCESSING, $batch->refresh()->status);
    }

    public function test_the_register_action_links_to_the_complete_register(): void
    {
        $this->app->setLocale('pt_BR');

        $batch = CteEmissionBatch::factory()->create();
        $register = Register::factory()->create([
            'vehicle_model' => 'Sprinter 416',
            'vehicle_plate' => 'ABC1D23',
            'vehicle_id' => 'VEH-987',
            'company' => 'millan',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Campinas',
            'deadline_withdraw' => '2026-08-10',
            'deadline_delivery' => '2026-08-12',
            'collected_date' => '2026-08-11',
            'delivery_confirmed_at' => Carbon::create(2026, 8, 12, 18, 45, 'UTC'),
            'driver' => 'João da Silva',
            'driver_plate' => 'XYZ9K87',
            'tow_yard' => 'Pátio Central',
            'status' => RegisterStatusEnum::COLLECTED,
            'payment_code' => 'PAY-123',
            'insurance' => 'Allianz Seguros',
            'fipe_value' => '43897.00',
            'value' => '1250.00',
            'pdf_path' => 'registers/abc1d23.pdf',
            'notes' => 'Carga liberada para transporte.',
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
        ]);

        $component = Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertTableActionHasLabel('viewRegister', 'Ver registro', $document)
            ->mountTableAction('viewRegister', $document)
            ->assertTableActionMounted('viewRegister')
            ->assertSee('Identificação')
            ->assertSee('Rota e prazos')
            ->assertSee('Operação')
            ->assertSee('Financeiro')
            ->assertSee('Veículo')
            ->assertSee('Sprinter 416')
            ->assertSee('Placa')
            ->assertSee('ABC1D23')
            ->assertSee('Código do veículo')
            ->assertSee('VEH-987')
            ->assertSee('Empresa')
            ->assertSee('millan')
            ->assertSee('Origem')
            ->assertSee('São Paulo')
            ->assertSee('Destino')
            ->assertSee('Campinas')
            ->assertSee('Retirada até')
            ->assertSee('10/08/2026')
            ->assertSee('Entrega até')
            ->assertSee('12/08/2026')
            ->assertSee('Data da recolha')
            ->assertSee('11/08/2026')
            ->assertSee('Entrega confirmada')
            ->assertSee('12/08/2026 15:45')
            ->assertSee('Motorista')
            ->assertSee('João da Silva')
            ->assertSee('Placa guincho')
            ->assertSee('XYZ9K87')
            ->assertSee('Pátio')
            ->assertSee('Pátio Central')
            ->assertSee('Situação')
            ->assertSee('Coletado')
            ->assertSee('Código pagamento')
            ->assertSee('PAY-123')
            ->assertSee('Seguradora')
            ->assertSee('Allianz Seguros')
            ->assertSee('Valor FIPE')
            ->assertSee('R$')
            ->assertSee('43.897,00')
            ->assertSee('Valor do serviço')
            ->assertSee('1.250,00')
            ->assertDontSee('PDF')
            ->assertDontSee('registers/abc1d23.pdf')
            ->assertSee('Observações')
            ->assertSee('Carga liberada para transporte.')
            ->assertSee('Abrir registro completo');

        $action = $component->instance()->getMountedTableAction();

        $this->assertNull($action->getModalSubmitAction());
        $this->assertSame(MaxWidth::FiveExtraLarge, $action->getModalWidth());
        $this->assertTrue($action->isModalHeaderSticky());
        $this->assertTrue($action->isModalFooterSticky());
        $this->assertSame(
            RegisterResource::getUrl('view', ['record' => $register]),
            $action->getModalAction('openRegister')->getUrl(),
        );
    }

    public function test_the_register_action_shows_placeholders_for_missing_optional_data(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $register = Register::factory()->create([
            'collected_date' => null,
            'delivery_confirmed_at' => null,
            'driver' => null,
            'driver_plate' => null,
            'tow_yard' => null,
            'payment_code' => null,
            'insurance' => null,
            'fipe_value' => null,
            'pdf_path' => null,
            'notes' => null,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->mountTableAction('viewRegister', $document)
            ->assertSee('Não informado');
    }
}

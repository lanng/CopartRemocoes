<?php

namespace Tests\Feature\Filament;

use App\Enums\CteDocumentStatusEnum;
use App\Filament\Resources\RegisterResource\Pages\ListRegisters;
use App\Filament\Resources\RegisterResource\Pages\ViewRegister;
use App\Models\CteDocument;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RegisterResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    public function test_registers_can_be_searched_by_historical_cte_number(): void
    {
        $linkedRegister = Register::factory()->create(['vehicle_model' => 'Linked vehicle']);
        $unlinkedRegister = Register::factory()->create(['vehicle_model' => 'Unlinked vehicle']);

        CteDocument::factory()->create([
            'register_id' => $linkedRegister->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '900100',
        ]);
        CteDocument::factory()->create([
            'register_id' => $linkedRegister->id,
            'status' => CteDocumentStatusEnum::SUPERSEDED,
            'cte_number' => '800001',
        ]);
        CteDocument::factory()->create([
            'register_id' => $unlinkedRegister->id,
            'status' => CteDocumentStatusEnum::QUEUED,
            'cte_number' => '900200',
        ]);

        Livewire::test(ListRegisters::class)
            ->searchTable('800001')
            ->assertCanSeeTableRecords([$linkedRegister])
            ->assertCanNotSeeTableRecords([$unlinkedRegister]);
    }

    public function test_register_view_shows_the_latest_authorized_cte_and_delivery_date(): void
    {
        $register = Register::factory()->create([
            'delivery_confirmed_at' => Carbon::create(2026, 8, 12, 15, 30, 'UTC'),
        ]);

        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '100001',
            'issued_at' => Carbon::create(2026, 8, 10, 12, 0, 'UTC'),
            'authorized_at' => Carbon::create(2026, 8, 10, 13, 0, 'UTC'),
        ]);
        $latest = CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '100002',
            'issued_at' => Carbon::create(2026, 8, 11, 12, 0, 'UTC'),
            'authorized_at' => Carbon::create(2026, 8, 11, 13, 0, 'UTC'),
            'protocol' => 'protocol-secret',
            'access_key' => '35260812563112000130570010000026701338262343',
        ]);

        Livewire::test(ViewRegister::class, ['record' => $register->getRouteKey()])
            ->assertFormFieldExists('vehicle_model')
            ->assertFormFieldIsDisabled('vehicle_model')
            ->assertFormFieldExists('vehicle_plate')
            ->assertFormFieldIsDisabled('vehicle_plate')
            ->assertFormFieldExists('origin_city')
            ->assertFormFieldIsDisabled('origin_city')
            ->assertFormFieldExists('status')
            ->assertFormFieldIsDisabled('status')
            ->assertFormFieldExists('consignor_letter_path')
            ->assertFormFieldExists('value')
            ->assertFormFieldIsDisabled('value')
            ->assertSee('Número do CT-e')
            ->assertSee('100002')
            ->assertSee('Emissão do CT-e')
            ->assertSee('11/08/2026 09:00')
            ->assertSee('Data da entrega')
            ->assertSee('12/08/2026 12:30')
            ->assertDontSee($latest->protocol)
            ->assertDontSee($latest->access_key);
    }

    public function test_register_view_shows_placeholders_when_cte_and_delivery_are_missing(): void
    {
        $register = Register::factory()->create();

        Livewire::test(ViewRegister::class, ['record' => $register->getRouteKey()])
            ->assertSee('Número do CT-e')
            ->assertSee('Emissão do CT-e')
            ->assertSee('Data da entrega')
            ->assertSee('Não informado');
    }

    public function test_register_list_and_view_show_unresolved_removal_imports(): void
    {
        $register = Register::factory()->create();
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'register_id' => $register->id,
        ]);

        Livewire::test(ListRegisters::class)
            ->assertSee('Revisão pendente');

        $this->assertTrue($register->unresolvedRemovalImports()->whereKey($item->id)->exists());
    }

    public function test_register_integration_column_summarizes_removal_alerts(): void
    {
        $register = Register::factory()->create();
        IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'register_id' => $register->id,
            'alerts' => ['freight_changed', 'zero_fipe'],
        ]);

        Livewire::test(ListRegisters::class)
            ->assertSee('Frete alterado, FIPE zerada');
    }

    public function test_register_integration_column_shows_readded_alert(): void
    {
        $register = Register::factory()->create(['status' => 'pending']);
        IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'register_id' => $register->id,
            'alerts' => ['register_readded'],
        ]);

        Livewire::test(ListRegisters::class)
            ->assertSee('Registro readicionado');
    }

    public function test_acknowledging_from_the_integration_column_resolves_the_alert(): void
    {
        $register = Register::factory()->create();
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'register_id' => $register->id,
            'alerts' => ['zero_fipe'],
        ]);

        Livewire::test(ListRegisters::class)
            ->callTableAction('acknowledgeRemovalImport', $register);

        $this->assertSame('processed', $item->refresh()->status);
        $this->assertNotNull($item->resolved_at);
        $this->assertSame(auth()->id(), $item->resolved_by);

        Livewire::test(ListRegisters::class)
            ->assertDontSee('FIPE zerada');
    }

    public function test_blocked_removal_imports_do_not_offer_acknowledgement_from_the_integration_column(): void
    {
        $register = Register::factory()->create();
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'register_id' => $register->id,
            'failure_reason' => 'update_blocked_by_status',
        ]);

        $table = Livewire::test(ListRegisters::class)->instance()->getTable();
        $acknowledgeAction = $table->getColumn('unresolved_removal_imports_exists')->getAction()
            ->record($register);

        $this->assertFalse($acknowledgeAction->isVisible());

        Livewire::test(ListRegisters::class)
            ->assertSee('Atualização bloqueada pelo status');

        $this->assertSame('pending', $item->refresh()->status);
        $this->assertNull($item->resolved_at);
    }

    public function test_register_alert_uses_a_table_action_instead_of_a_nested_link(): void
    {
        $register = Register::factory()->create();
        IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'register_id' => $register->id,
        ]);
        $register->load('unresolvedRemovalImports');

        $table = Livewire::test(ListRegisters::class)->instance()->getTable();
        $alertColumn = $table->getColumn('unresolved_removal_imports_exists')->record($register);

        $this->assertNull($alertColumn->getUrl());
        $this->assertTrue($table->hasAction('viewRemovalImport'));
        $this->assertTrue($table->getAction('viewRemovalImport')->record($register)->isVisible());
    }

    public function test_register_list_shows_the_consignor_letter_when_it_exists(): void
    {
        $register = Register::factory()->create([
            'consignor_letter_path' => 'registros/copart/123/CartaDoComitente ABC1D23.pdf',
        ]);

        $table = Livewire::test(ListRegisters::class)->instance()->getTable();
        $column = $table->getColumn('consignor_letter_path')->record($register);

        $this->assertSame('Comitente', $column->formatState($column->getState()));
        $this->assertNotNull($column->getUrl());
    }
}

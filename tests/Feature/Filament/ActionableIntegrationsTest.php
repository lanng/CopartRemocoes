<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\ActionableIntegrations;
use App\Jobs\ProcessRemovalRequestEmail;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ActionableIntegrationsTest extends TestCase
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

    public function test_it_lists_only_the_ten_highest_priority_actionable_items(): void
    {
        $missingCte = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'processed',
            'delivery_alert' => 'missing_authorized_cte',
            'received_at' => '2026-08-10 10:00:00',
        ]);
        $removalError = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'failure_reason' => 'domain_error',
            'received_at' => '2026-08-11 10:00:00',
        ]);
        $unexpectedFlow = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'processed',
            'delivery_alert' => 'unexpected_status',
            'received_at' => '2026-08-12 10:00:00',
        ]);
        $checklistPending = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'pending',
            'received_at' => '2026-08-13 10:00:00',
        ]);
        $removalAlert = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'alerts' => ['zero_fipe'],
            'received_at' => '2026-08-14 10:00:00',
        ]);
        $extraItems = IntegrationInboxItem::factory()->count(5)->create([
            'message_type' => 'checklist',
            'status' => 'pending',
            'received_at' => '2026-08-15 10:00:00',
        ]);
        $resolved = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'processed',
            'resolved_at' => now(),
        ]);

        $expected = collect([$missingCte, $removalAlert, $removalError, $unexpectedFlow])
            ->concat($extraItems->reverse()->values())
            ->concat([$checklistPending])
            ->all();

        $component = Livewire::test(ActionableIntegrations::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($expected, inOrder: true)
            ->assertCanNotSeeTableRecords([$resolved])
            ->assertSee('Ações necessárias')
            ->assertSee('Ver todas as integrações');

        $this->assertCount(10, $component->instance()->getTableRecords());

        $eagerLoads = $component->instance()->getTable()->getQuery()->getEagerLoads();
        $this->assertArrayHasKey('register', $eagerLoads);
        $this->assertArrayHasKey('resolver', $eagerLoads);
        $this->assertArrayHasKey('acknowledger', $eagerLoads);
    }

    public function test_it_exposes_the_correct_action_for_each_integration_state(): void
    {
        $deliveryAlert = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'processed',
            'delivery_alert' => 'missing_authorized_cte',
        ]);
        $removalError = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'failure_reason' => 'domain_error',
        ]);
        $removalAlert = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'alerts' => ['zero_fipe'],
        ]);

        $component = Livewire::test(ActionableIntegrations::class);
        $table = $component->instance()->getTable();

        $this->assertTrue($table->getAction('conciliarChecklist')->record($deliveryAlert)->isVisible());
        $this->assertTrue($table->getAction('retryRemovalRequest')->record($removalError)->isVisible());
        $this->assertTrue($table->getAction('acknowledgeRemovalAlert')->record($removalAlert)->isVisible());

        $consignorLetterFailure = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'alerts' => ['consignor_letter_failed'],
        ]);
        $this->assertTrue($table->getAction('retryRemovalRequest')->record($consignorLetterFailure)->isVisible());

        $component->callTableAction('conciliarChecklist', $deliveryAlert);
        Queue::fake();
        $component->callTableAction('retryRemovalRequest', $removalError);

        $this->assertNotNull($deliveryAlert->refresh()->acknowledged_at);
        $this->assertSame('queued', $removalError->refresh()->status);
        Queue::assertPushed(ProcessRemovalRequestEmail::class);
    }

    public function test_it_reviews_a_status_blocked_import_directly_from_the_widget(): void
    {
        $register = Register::factory()->create([
            'company' => 'copart',
            'status' => 'available',
            'destination_city' => 'Pirapora',
        ]);
        $blocked = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'failure_reason' => 'update_blocked_by_status',
            'register_id' => $register->id,
            'proposed_changes' => [
                'destination_city' => ['current' => 'Pirapora', 'proposed' => 'Caçapava'],
            ],
            'received_at' => '2026-09-01 14:36:00',
        ]);

        $component = Livewire::test(ActionableIntegrations::class);
        $table = $component->instance()->getTable();
        $reviewAction = $table->getAction('reviewRemovalRequest')->record($blocked);

        $this->assertTrue($reviewAction->isVisible());
        $this->assertNull($reviewAction->getUrl());

        $component->callTableAction('reviewRemovalRequest', $blocked, [
            'fields' => ['destination_city'],
            'replace_pdf' => false,
        ]);

        $this->assertSame('Caçapava', $register->refresh()->destination_city);
        $this->assertSame('processed', $blocked->refresh()->status);
        $this->assertNull($blocked->failure_reason);
    }

    public function test_it_keeps_the_register_status_when_conciliating_a_pending_checklist_alert(): void
    {
        $register = Register::factory()->create([
            'company' => 'copart',
            'status' => 'collected',
        ]);
        $pendingAlert = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'pending',
            'delivery_alert' => 'unexpected_status',
            'register_id' => $register->id,
            'received_at' => '2026-08-19 12:00:00',
        ]);

        $component = Livewire::test(ActionableIntegrations::class);
        $table = $component->instance()->getTable();

        $this->assertTrue($table->getAction('conciliarChecklist')->record($pendingAlert)->isVisible());

        $component->callTableAction('conciliarChecklist', $pendingAlert, ['decisao' => 'keep']);

        $this->assertSame('processed', $pendingAlert->refresh()->status);
        $this->assertSame('status_kept_by_user', $pendingAlert->failure_reason);
        $this->assertNotNull($pendingAlert->acknowledged_at);
        $this->assertNotNull($pendingAlert->resolved_at);
        $this->assertSame('collected', $register->refresh()->status->value);
        $this->assertSame('2026-08-19 12:00:00', $register->delivery_confirmed_at->toDateTimeString());
    }

    public function test_it_delivers_the_register_when_conciliating_with_the_delivery_choice(): void
    {
        $register = Register::factory()->create([
            'company' => 'copart',
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'collected',
        ]);
        $pendingAlert = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'pending',
            'delivery_alert' => 'missing_authorized_cte',
            'register_id' => $register->id,
            'extracted_vehicle_id' => '1146609',
            'extracted_vehicle_plate' => 'ESN4A20',
            'received_at' => '2026-08-19 12:00:00',
        ]);

        Livewire::test(ActionableIntegrations::class)
            ->callTableAction('conciliarChecklist', $pendingAlert, ['decisao' => 'deliver']);

        $this->assertSame('processed', $pendingAlert->refresh()->status);
        $this->assertSame('Baixa conciliada manualmente', $pendingAlert->failure_reason);
        $this->assertNotNull($pendingAlert->resolved_by);
        $this->assertSame('delivered', $register->refresh()->status->value);
        $this->assertSame('2026-08-19 12:00:00', $register->delivery_confirmed_at->toDateTimeString());
    }

    public function test_it_renders_a_positive_empty_state(): void
    {
        Livewire::test(ActionableIntegrations::class)
            ->assertSuccessful()
            ->assertSee('Nenhuma ação pendente')
            ->assertSee('As integrações que exigirem intervenção aparecerão aqui.');
    }

    public function test_it_shows_the_consignor_letter_failure_as_an_actionable_alert(): void
    {
        IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'alerts' => ['consignor_letter_failed'],
        ]);

        Livewire::test(ActionableIntegrations::class)
            ->assertSee('Falha ao salvar Carta do Comitente');
    }

    public function test_it_prioritizes_removal_import_failures_over_old_checklist_pendencies(): void
    {
        IntegrationInboxItem::factory()->count(10)->create([
            'message_type' => 'checklist',
            'status' => 'pending',
            'received_at' => now()->subDays(10),
        ]);
        IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'alerts' => ['consignor_letter_failed'],
            'received_at' => now(),
        ]);

        Livewire::test(ActionableIntegrations::class)
            ->assertSee('Falha ao salvar Carta do Comitente');
    }

    public function test_it_is_registered_on_the_admin_dashboard(): void
    {
        $widgets = Filament::getPanel('admin')->getWidgets();

        $this->assertContains(ActionableIntegrations::class, $widgets);
        $this->assertNotContains(AccountWidget::class, $widgets);
        $this->assertNotContains(FilamentInfoWidget::class, $widgets);
    }
}

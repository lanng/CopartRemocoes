<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\IntegrationInboxItemResource;
use App\Filament\Resources\IntegrationInboxItemResource\Pages\ListIntegrationInboxItems;
use App\Filament\Resources\IntegrationInboxItemResource\Pages\ViewIntegrationInboxItem;
use App\Models\IntegrationInboxItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class IntegrationInboxItemResourceTest extends TestCase
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

    public function test_it_exposes_the_translated_financial_inbox(): void
    {
        $pending = IntegrationInboxItem::factory()->create([
            'status' => 'pending',
            'failure_reason' => 'vehicle_plate_mismatch',
            'received_at' => Carbon::create(2026, 8, 13, 2, 30, 'UTC'),
        ]);
        $processed = IntegrationInboxItem::factory()->create(['status' => 'processed']);

        $this->assertSame('Integrações por e-mail', IntegrationInboxItemResource::getNavigationLabel());
        $this->assertSame('Financeiro', IntegrationInboxItemResource::getNavigationGroup());

        Livewire::test(ListIntegrationInboxItems::class)
            ->assertSee('Pendente')
            ->assertSee('Placa divergente')
            ->assertSee('12/08/2026')
            ->assertSee('23:30')
            ->assertSee('Conciliar')
            ->assertDontSee('ignored')
            ->assertDontSee($processed->external_id);

        $this->assertSame('Processado', $processed->refresh()->statusLabel());
        $this->assertSame('Placa divergente', $pending->failureReasonLabel());
    }

    public function test_it_exposes_the_compact_table_columns_in_order(): void
    {
        $list = Livewire::test(ListIntegrationInboxItems::class);
        $table = $list->instance()->getTable();

        $this->assertSame(
            ['status', 'message_type', 'extracted_vehicle_id', 'received_at', 'occurrence'],
            array_keys($table->getColumns()),
        );
        $this->assertTrue($table->getColumn('status')->isBadge());
        $this->assertSame('Veículo', $table->getColumn('extracted_vehicle_id')->getLabel());
        $this->assertSame('Recebido', $table->getColumn('received_at')->getLabel());
        $this->assertSame('Ocorrência', $table->getColumn('occurrence')->getLabel());
        $this->assertSame(['extracted_vehicle_id', 'extracted_vehicle_plate', 'sender'], $table->getColumn('extracted_vehicle_id')->getSearchColumns());
        $this->assertTrue($table->getColumn('occurrence')->isBadge());
    }

    public function test_it_distinguishes_delivery_and_register_inclusion_messages(): void
    {
        $checklist = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'processed',
            'delivery_alert' => 'unexpected_status',
        ]);
        $removal = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'processed',
        ]);
        $table = Livewire::test(ListIntegrationInboxItems::class)->instance()->getTable();

        $this->assertSame('Baixa de entrega', $checklist->messageTypeLabel());
        $this->assertSame('Inclusão de registro', $removal->messageTypeLabel());
        $messageTypeColumn = $table->getColumn('message_type');
        $this->assertSame('Baixa de entrega', $messageTypeColumn->formatState($messageTypeColumn->record($checklist)->getState()));
        $this->assertSame('Inclusão de registro', $messageTypeColumn->formatState($messageTypeColumn->record($removal)->getState()));
    }

    public function test_it_acknowledges_delivery_alerts_from_the_inbox(): void
    {
        $checklist = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'processed',
            'delivery_alert' => 'missing_authorized_cte',
        ]);
        $user = User::query()->firstOrFail();

        $table = Livewire::test(ListIntegrationInboxItems::class)->instance()->getTable();
        $this->assertTrue($table->getAction('conciliarChecklist')->record($checklist)->isVisible());

        Livewire::test(ListIntegrationInboxItems::class)
            ->callTableAction('conciliarChecklist', $checklist);

        $this->assertSame($user->id, $checklist->refresh()->acknowledged_by);
        $this->assertNotNull($checklist->acknowledged_at);
    }

    public function test_it_renders_vehicle_id_and_plate_description(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'extracted_vehicle_id' => 'VEHICLE-123',
            'extracted_vehicle_plate' => 'ABC1D23',
        ]);
        $table = Livewire::test(ListIntegrationInboxItems::class)->instance()->getTable();
        $vehicleColumn = $table->getColumn('extracted_vehicle_id')->record($item);

        $this->assertSame('VEHICLE-123', $vehicleColumn->getState());
        $this->assertSame('ABC1D23', $vehicleColumn->getDescriptionBelow());
    }

    public function test_it_prioritizes_alerts_and_uses_failure_reason_without_a_placeholder(): void
    {
        $alert = IntegrationInboxItem::factory()->create([
            'delivery_alert' => 'unexpected_status',
            'failure_reason' => 'vehicle_plate_mismatch',
        ]);
        $dangerAlert = IntegrationInboxItem::factory()->create([
            'delivery_alert' => 'missing_authorized_cte',
            'failure_reason' => null,
        ]);
        $failure = IntegrationInboxItem::factory()->create([
            'delivery_alert' => null,
            'failure_reason' => 'vehicle_plate_mismatch',
        ]);
        $normal = IntegrationInboxItem::factory()->create([
            'delivery_alert' => null,
            'failure_reason' => null,
        ]);
        $table = Livewire::test(ListIntegrationInboxItems::class)->instance()->getTable();
        $occurrenceColumn = $table->getColumn('occurrence');
        $this->assertNotNull($occurrenceColumn);

        $this->assertSame('Fluxo inesperado', $occurrenceColumn->formatState($occurrenceColumn->record($alert)->getState()));
        $this->assertSame('warning', $occurrenceColumn->getColor($occurrenceColumn->getState()));
        $this->assertSame('Entrega sem CT-e', $occurrenceColumn->formatState($occurrenceColumn->record($dangerAlert)->getState()));
        $this->assertSame('danger', $occurrenceColumn->getColor($occurrenceColumn->getState()));
        $this->assertSame('Placa divergente', $occurrenceColumn->formatState($occurrenceColumn->record($failure)->getState()));
        $this->assertSame('gray', $occurrenceColumn->getColor($occurrenceColumn->getState()));
        $this->assertNull($occurrenceColumn->record($normal)->getState());
        $this->assertNull($occurrenceColumn->getPlaceholder());

        $list = Livewire::test(ListIntegrationInboxItems::class)
            ->assertSee('Fluxo inesperado')
            ->assertSee('Entrega sem CT-e')
            ->assertSee('Placa divergente');

        $this->assertStringNotContainsString('Sem alerta', Str::between($list->html(), '<tbody', '</tbody>'));
    }

    public function test_it_searches_vehicle_id_and_plate_globally(): void
    {
        $idMatch = IntegrationInboxItem::factory()->create(['extracted_vehicle_id' => 'ID-SEARCH-123']);
        $plateMatch = IntegrationInboxItem::factory()->create(['extracted_vehicle_plate' => 'PLT9Z99']);
        $other = IntegrationInboxItem::factory()->create([
            'extracted_vehicle_id' => 'OTHER-456',
            'extracted_vehicle_plate' => 'OTH1R11',
        ]);

        Livewire::test(ListIntegrationInboxItems::class)
            ->searchTable('ID-SEARCH-123')
            ->assertCanSeeTableRecords([$idMatch])
            ->assertCanNotSeeTableRecords([$plateMatch, $other]);

        Livewire::test(ListIntegrationInboxItems::class)
            ->searchTable('PLT9Z99')
            ->assertCanSeeTableRecords([$plateMatch])
            ->assertCanNotSeeTableRecords([$idMatch, $other]);
    }

    public function test_it_searches_sender_globally_without_reintroducing_a_sender_column(): void
    {
        $senderMatch = IntegrationInboxItem::factory()->create(['sender' => 'matching-sender@example.com']);
        $other = IntegrationInboxItem::factory()->create(['sender' => 'other-sender@example.com']);

        Livewire::test(ListIntegrationInboxItems::class)
            ->searchTable('matching-sender@example.com')
            ->assertCanSeeTableRecords([$senderMatch])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_it_formats_received_at_and_exposes_its_responsive_sortable_configuration(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'received_at' => Carbon::create(2026, 8, 13, 2, 30, 'UTC'),
        ]);
        $list = Livewire::test(ListIntegrationInboxItems::class);
        $receivedAtColumn = $list->instance()->getTable()->getColumn('received_at');

        $this->assertSame('md', $receivedAtColumn->getVisibleFrom());
        $this->assertTrue($receivedAtColumn->isSortable());

        $receivedAt = $receivedAtColumn->record($item)->getState();
        $this->assertSame('12/08/2026', $receivedAtColumn->formatState($receivedAt));
        $this->assertSame('23:30', $receivedAtColumn->getDescriptionBelow());
        $list->assertSee('12/08/2026')->assertSee('23:30');
        $this->assertSame('UTC', $item->received_at->getTimezone()->getName());
    }

    public function test_it_filters_by_status_and_preserves_delivery_alert_filters(): void
    {
        $pending = IntegrationInboxItem::factory()->create(['status' => 'pending']);
        $processed = IntegrationInboxItem::factory()->create(['status' => 'processed']);
        $alert = IntegrationInboxItem::factory()->create([
            'status' => 'processed',
            'delivery_alert' => 'unexpected_status',
        ]);

        Livewire::test(ListIntegrationInboxItems::class)
            ->filterTable('status', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$processed, $alert]);

        Livewire::test(ListIntegrationInboxItems::class)
            ->filterTable('has_delivery_alert', true)
            ->assertCanSeeTableRecords([$alert])
            ->assertCanNotSeeTableRecords([$pending, $processed]);

        Livewire::test(ListIntegrationInboxItems::class)
            ->filterTable('has_delivery_alert', false)
            ->assertCanSeeTableRecords([$pending, $processed])
            ->assertCanNotSeeTableRecords([$alert]);
    }

    public function test_it_keeps_view_action_and_resolve_visibility_by_status(): void
    {
        $pending = IntegrationInboxItem::factory()->create(['status' => 'pending']);
        $processed = IntegrationInboxItem::factory()->create(['status' => 'processed']);
        $table = Livewire::test(ListIntegrationInboxItems::class)->instance()->getTable();
        $resolveAction = $table->getAction('conciliarChecklist');

        $this->assertTrue($table->hasAction('view'));
        $this->assertNotNull($resolveAction);
        $this->assertTrue($resolveAction->record($pending)->isVisible());
        $this->assertFalse($resolveAction->record($processed)->isVisible());
    }

    public function test_it_exposes_removal_request_review_actions_without_using_checklist_reconciliation(): void
    {
        $pending = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'proposed_changes' => [
                'destination_city' => ['current' => 'Pirapora', 'proposed' => 'Jundiaí'],
            ],
        ]);
        $alert = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'alerts' => ['zero_fipe'],
        ]);
        $table = Livewire::test(ListIntegrationInboxItems::class)->instance()->getTable();

        $this->assertTrue($table->getAction('reviewRemovalRequest')->record($pending)->isVisible());
        $this->assertFalse($table->getAction('conciliarChecklist')->record($pending)->isVisible());
        $this->assertTrue($table->getAction('acknowledgeRemovalAlert')->record($alert)->isVisible());
        $this->assertSame(['FIPE zerada'], $alert->removalAlertLabels());
    }

    public function test_it_exposes_accept_and_retry_actions_for_removal_imports(): void
    {
        $register = \App\Models\Register::factory()->create();
        $removal = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'register_id' => $register->id,
            'proposed_changes' => [
                'value' => ['current' => '800.00', 'proposed' => '866.48'],
            ],
        ]);
        $domainError = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'failure_reason' => 'domain_error',
        ]);
        $consignorLetterFailure = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'alerts' => ['consignor_letter_failed'],
        ]);
        $checklist = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'pending',
        ]);
        $table = Livewire::test(ListIntegrationInboxItems::class)->instance()->getTable();

        $this->assertTrue($table->getAction('acceptRemovalRequest')->record($removal)->isVisible());
        $this->assertTrue($table->getAction('reviewRemovalRequest')->record($removal)->isVisible());
        $this->assertTrue($table->getAction('retryRemovalRequest')->record($domainError)->isVisible());
        $this->assertTrue($table->getAction('retryRemovalRequest')->record($consignorLetterFailure)->isVisible());
        $this->assertFalse($table->getAction('acceptRemovalRequest')->record($domainError)->isVisible());
        $this->assertFalse($table->getAction('retryRemovalRequest')->record($checklist)->isVisible());
        $this->assertSame('Falha na validação da importação', $domainError->failureReasonLabel());
    }

    public function test_it_disables_checklist_reconciliation_when_no_register_matches(): void
    {
        $checklist = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'pending',
            'extracted_vehicle_id' => 'missing-vehicle',
            'extracted_vehicle_plate' => 'ZZZ9Z99',
        ]);
        $table = Livewire::test(ListIntegrationInboxItems::class)->instance()->getTable();
        $resolveAction = $table->getAction('conciliarChecklist')->record($checklist);

        $this->assertTrue($resolveAction->isVisible());
        $this->assertTrue($resolveAction->isDisabled());
    }

    public function test_it_accepts_all_removal_changes_and_can_retry_a_processing_failure(): void
    {
        Queue::fake();
        $register = \App\Models\Register::factory()->create(['value' => '800.00']);
        $removal = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'register_id' => $register->id,
            'proposed_changes' => [
                'value' => ['current' => '800.00', 'proposed' => '866.48'],
            ],
        ]);
        $domainError = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'failure_reason' => 'domain_error',
        ]);

        Livewire::test(ListIntegrationInboxItems::class)
            ->callTableAction('acceptRemovalRequest', $removal)
            ->callTableAction('retryRemovalRequest', $domainError);

        $this->assertSame('processed', $removal->refresh()->status);
        $this->assertSame('866.48', $register->refresh()->value);
        $this->assertSame('queued', $domainError->refresh()->status);
        Queue::assertPushed(\App\Jobs\ProcessRemovalRequestEmail::class, fn (\App\Jobs\ProcessRemovalRequestEmail $job): bool => $job->integrationInboxItemId === $domainError->id);
    }

    public function test_it_displays_proposed_removal_changes_in_the_item_view(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'proposed_changes' => [
                'destination_city' => ['current' => 'Pirapora', 'proposed' => 'Jundiaí'],
            ],
        ]);

        Livewire::test(ViewIntegrationInboxItem::class, ['record' => $item->getRouteKey()])
            ->assertSee('Alterações propostas')
            ->assertSee('Cidade de destino')
            ->assertSee('Pirapora')
            ->assertSee('Jundiaí');
    }

    public function test_it_sorts_by_received_at_in_both_directions(): void
    {
        $oldest = IntegrationInboxItem::factory()->create([
            'received_at' => Carbon::create(2026, 8, 10, 12, 0, 'UTC'),
        ]);
        $middle = IntegrationInboxItem::factory()->create([
            'received_at' => Carbon::create(2026, 8, 11, 12, 0, 'UTC'),
        ]);
        $newest = IntegrationInboxItem::factory()->create([
            'received_at' => Carbon::create(2026, 8, 12, 12, 0, 'UTC'),
        ]);

        Livewire::test(ListIntegrationInboxItems::class)
            ->sortTable('received_at', 'asc')
            ->assertCanSeeTableRecords([$oldest, $middle, $newest], inOrder: true)
            ->sortTable('received_at', 'desc')
            ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
    }

    public function test_it_lists_and_filters_delivery_alerts_with_semantic_row_classes(): void
    {
        $withoutAlert = IntegrationInboxItem::factory()->create(['status' => 'processed']);
        $unexpectedStatus = IntegrationInboxItem::factory()->create([
            'status' => 'processed',
            'delivery_alert' => 'unexpected_status',
        ]);
        $missingAuthorizedCte = IntegrationInboxItem::factory()->create([
            'status' => 'processed',
            'delivery_alert' => 'missing_authorized_cte',
        ]);

        $list = Livewire::test(ListIntegrationInboxItems::class)
            ->assertCanSeeTableRecords([$withoutAlert, $unexpectedStatus, $missingAuthorizedCte])
            ->assertSee('Fluxo inesperado')
            ->assertSee('Entrega sem CT-e');

        $this->assertStringNotContainsString('Sem alerta', Str::between($list->html(), '<tbody', '</tbody>'));

        $this->assertSame(
            ['integration-inbox-alert-warning'],
            $list->instance()->getTable()->getRecordClasses($unexpectedStatus),
        );
        $this->assertSame(
            ['integration-inbox-alert-danger'],
            $list->instance()->getTable()->getRecordClasses($missingAuthorizedCte),
        );
        $this->assertSame([], $list->instance()->getTable()->getRecordClasses($withoutAlert));

        Livewire::test(ListIntegrationInboxItems::class)
            ->filterTable('has_delivery_alert', true)
            ->assertCanSeeTableRecords([$unexpectedStatus, $missingAuthorizedCte])
            ->assertCanNotSeeTableRecords([$withoutAlert]);

        Livewire::test(ListIntegrationInboxItems::class)
            ->filterTable('has_delivery_alert', false)
            ->assertCanSeeTableRecords([$withoutAlert])
            ->assertCanNotSeeTableRecords([$unexpectedStatus, $missingAuthorizedCte]);
    }

    public function test_it_shows_delivery_alert_snapshot_in_the_inbox_item_view(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'status' => 'processed',
            'previous_register_status' => 'collected',
            'delivery_alert' => 'unexpected_status',
            'authorized_cte_number_at_delivery' => null,
        ]);

        Livewire::test(ViewIntegrationInboxItem::class, ['record' => $item->getRouteKey()])
            ->assertSee('Status anterior')
            ->assertSee('Nível do alerta')
            ->assertSee('CT-e autorizado na baixa')
            ->assertSee('Coletado')
            ->assertSee('Fluxo inesperado')
            ->assertSee('Não encontrado');

        $sourceCssPath = resource_path('css/filament/integration-inbox-alerts.css');
        $publishedCssPath = public_path('css/app/integration-inbox-alerts.css');

        $this->assertTrue(File::exists($sourceCssPath));
        $this->assertTrue(File::exists($publishedCssPath));
        $this->assertSame(File::get($sourceCssPath), File::get($publishedCssPath));

        $publishedCss = File::get($publishedCssPath);
        $this->assertStringContainsString('.integration-inbox-alert-warning', $publishedCss);
        $this->assertStringContainsString('.integration-inbox-alert-danger', $publishedCss);
    }

    public function test_it_uses_a_placeholder_for_a_missing_previous_status_snapshot(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'status' => 'processed',
            'previous_register_status' => null,
        ]);

        $view = Livewire::test(ViewIntegrationInboxItem::class, ['record' => $item->getRouteKey()]);
        $infolist = $view->instance()->getInfolist('infolist');

        $this->assertNotNull($infolist);

        $previousStatusEntry = collect($infolist->getComponents())
            ->first(fn ($entry): bool => $entry->getName() === 'previous_register_status');

        $this->assertNotNull($previousStatusEntry);
        $this->assertSame('Não informado', $previousStatusEntry->getPlaceholder());
    }
}

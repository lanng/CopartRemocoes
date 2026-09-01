<?php

namespace Tests\Feature\Services;

use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Models\User;
use App\Services\MicrosoftGraph\AcknowledgeIntegrationAlert;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcknowledgeIntegrationAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_acknowledges_a_delivery_alert_without_changing_resolution_data(): void
    {
        $user = User::factory()->create();
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'processed',
            'delivery_alert' => 'missing_authorized_cte',
            'resolved_at' => '2026-08-19 12:00:00',
        ]);

        $result = app(AcknowledgeIntegrationAlert::class)->handle($item, $user);

        $this->assertSame($user->id, $result->acknowledged_by);
        $this->assertNotNull($result->acknowledged_at);
        $this->assertSame('processed', $result->status);
        $this->assertSame('missing_authorized_cte', $result->delivery_alert);
        $this->assertSame('2026-08-19 12:00:00', $result->resolved_at->toDateTimeString());
    }

    public function test_it_keeps_the_register_status_and_resolves_a_pending_checklist_item(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create([
            'company' => 'copart',
            'status' => 'collected',
        ]);
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'pending',
            'delivery_alert' => 'unexpected_status',
            'register_id' => $register->id,
            'received_at' => '2026-08-19 12:00:00',
        ]);

        $result = app(AcknowledgeIntegrationAlert::class)->handle($item, $user);

        $this->assertSame($user->id, $result->acknowledged_by);
        $this->assertNotNull($result->acknowledged_at);
        $this->assertSame('processed', $result->status);
        $this->assertSame('status_kept_by_user', $result->failure_reason);
        $this->assertSame($user->id, $result->resolved_by);
        $this->assertNotNull($result->resolved_at);
        $this->assertSame('collected', $register->refresh()->status->value);
        $this->assertSame('2026-08-19 12:00:00', $register->delivery_confirmed_at->toDateTimeString());
    }

    public function test_it_keeps_an_existing_delivery_confirmation_date_when_keeping_the_status(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create([
            'company' => 'copart',
            'status' => 'collected',
            'delivery_confirmed_at' => '2026-08-01 10:00:00',
        ]);
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'pending',
            'delivery_alert' => 'missing_authorized_cte',
            'register_id' => $register->id,
            'received_at' => '2026-08-19 12:00:00',
        ]);

        app(AcknowledgeIntegrationAlert::class)->handle($item, $user);

        $this->assertSame('2026-08-01 10:00:00', $register->refresh()->delivery_confirmed_at->toDateTimeString());
    }

    public function test_it_rejects_items_that_are_not_unacknowledged_delivery_alerts(): void
    {
        $user = User::factory()->create();

        $withoutAlert = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'processed',
        ]);
        $this->expectException(DomainException::class);
        app(AcknowledgeIntegrationAlert::class)->handle($withoutAlert, $user);
    }

    public function test_it_rejects_already_acknowledged_alerts_and_removal_alerts(): void
    {
        $user = User::factory()->create();
        $alreadyAcknowledged = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'processed',
            'delivery_alert' => 'unexpected_status',
            'acknowledged_at' => now(),
        ]);
        $removalAlert = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'alerts' => ['zero_fipe'],
        ]);

        foreach ([$alreadyAcknowledged, $removalAlert] as $item) {
            try {
                app(AcknowledgeIntegrationAlert::class)->handle($item, $user);
                $this->fail('The item should not be acknowledged.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }
}

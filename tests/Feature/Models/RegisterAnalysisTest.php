<?php

namespace Tests\Feature\Models;

use App\Enums\RegisterStatusEnum;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaving_analysis_manually_completes_its_occurrences_only(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $register = Register::factory()->create(['status' => 'analysis']);
        $error = IntegrationInboxItem::factory()->create([
            'register_id' => $register->id, 'message_type' => 'cte_error', 'status' => 'alert',
        ]);
        $delivery = IntegrationInboxItem::factory()->create([
            'register_id' => $register->id, 'delivery_alert' => 'missing_authorized_cte',
        ]);
        $removal = IntegrationInboxItem::factory()->create([
            'register_id' => $register->id, 'message_type' => 'removal_request',
        ]);

        $register->update(['status' => RegisterStatusEnum::COLLECTED]);

        foreach ([$error, $delivery] as $item) {
            $this->assertSame('processed', $item->refresh()->status);
            $this->assertSame($user->id, $item->resolved_by);
            $this->assertNotNull($item->resolved_at);
            $this->assertFalse($item->requiresUserAction());
        }
        $this->assertNull($removal->refresh()->resolved_at);
    }

    public function test_editing_notes_does_not_complete_analysis(): void
    {
        $register = Register::factory()->create(['status' => 'analysis']);
        $item = IntegrationInboxItem::factory()->create([
            'register_id' => $register->id, 'message_type' => 'cte_error', 'status' => 'alert',
        ]);

        $register->update(['notes' => 'Aguardando correção.']);

        $this->assertNull($item->refresh()->resolved_at);
        $this->assertSame('analysis', $register->status->value);
    }

    public function test_automatic_completion_preserves_who_previously_recognized_the_error(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create(['status' => 'analysis']);
        $item = IntegrationInboxItem::factory()->create([
            'register_id' => $register->id, 'message_type' => 'cte_error', 'status' => 'alert',
            'acknowledged_at' => '2026-10-01 12:00:00', 'acknowledged_by' => $user->id,
        ]);

        $register->update(['status' => 'invoiced']);

        $this->assertSame($user->id, $item->refresh()->acknowledged_by);
        $this->assertSame('2026-10-01 12:00:00', $item->acknowledged_at->toDateTimeString());
        $this->assertNotNull($item->resolved_at);
    }
}

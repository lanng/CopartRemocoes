<?php

namespace Tests\Feature\Services;

use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Models\User;
use App\Services\MicrosoftGraph\ResolveIntegrationInboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveIntegrationInboxItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pending_item_can_be_conciliated_to_a_matching_register(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
        ]);
        $item = IntegrationInboxItem::factory()->create([
            'status' => 'pending',
            'extracted_vehicle_id' => '1146609',
            'extracted_vehicle_plate' => 'ESN4A20',
            'received_at' => '2026-08-06 17:51:36',
        ]);

        app(ResolveIntegrationInboxItem::class)->handle($item, $register, $user, 'Conferido no e-mail.');

        $this->assertSame('processed', $item->refresh()->status);
        $this->assertSame($register->id, $item->register_id);
        $this->assertSame('delivered', $register->refresh()->status->value);
        $this->assertSame($user->id, $item->resolved_by);
        $this->assertNotNull($item->resolved_at);
    }
}

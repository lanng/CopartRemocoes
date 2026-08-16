<?php

namespace Tests\Feature\Models;

use App\Models\Register;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RegisterDeliveryConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_confirmation_is_stored_as_a_datetime(): void
    {
        $register = Register::factory()->create([
            'delivery_confirmed_at' => Carbon::parse('2026-08-06T14:51:36-03:00')->utc(),
        ]);

        $this->assertInstanceOf(Carbon::class, $register->refresh()->delivery_confirmed_at);
        $this->assertSame('2026-08-06 17:51:36', $register->delivery_confirmed_at->utc()->format('Y-m-d H:i:s'));
    }
}

<?php

namespace Tests\Feature\Services;

use App\Models\PaymentBatch;
use App\Models\Register;
use App\Services\MicrosoftGraph\MicrosoftGraphClient;
use App\Services\MicrosoftGraph\SyncChecklistEmailsService;
use App\Services\Payments\GeneratePendingPaymentBatches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class GeneratePendingPaymentBatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attempts_outlook_before_generating_due_windows(): void
    {
        Config::set('payment_batches.start_date', '2026-08-01');
        Register::factory()->create([
            'status' => 'delivered',
            'delivery_confirmed_at' => '2026-08-08 12:00:00',
        ]);
        $sync = Mockery::mock(SyncChecklistEmailsService::class);
        $sync->expects('handle')->once()->andThrow(new \RuntimeException('Outlook indisponível'));
        $this->app->instance(SyncChecklistEmailsService::class, $sync);
        $this->app->instance(MicrosoftGraphClient::class, Mockery::mock(MicrosoftGraphClient::class));

        $result = app(GeneratePendingPaymentBatches::class)->handle('manual', '2026-08-21 07:00:00');

        $this->assertSame(1, $result['created']);
        $this->assertTrue(PaymentBatch::first()->outlook_sync_failed);
    }
}

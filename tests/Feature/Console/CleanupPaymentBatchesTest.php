<?php

namespace Tests\Feature\Console;

use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CleanupPaymentBatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_only_confirmed_batches_older_than_ninety_days(): void
    {
        $old = PaymentBatch::factory()->create([
            'status' => 'confirmed',
            'confirmed_at' => Carbon::now()->subDays(91),
        ]);
        PaymentBatchItem::factory()->create(['payment_batch_id' => $old->id]);
        $recent = PaymentBatch::factory()->create([
            'status' => 'confirmed',
            'window_start' => '2026-08-11',
            'window_end' => '2026-08-17',
            'confirmed_at' => Carbon::now()->subDays(89),
        ]);
        $pending = PaymentBatch::factory()->create([
            'status' => 'pending',
            'window_start' => '2026-08-18',
            'window_end' => '2026-08-24',
        ]);

        $this->artisan('payments:cleanup-batches')
            ->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelExists($pending);
    }

    public function test_it_keeps_a_confirmed_batch_at_the_ninety_day_boundary(): void
    {
        $batch = PaymentBatch::factory()->create([
            'status' => 'confirmed',
            'confirmed_at' => Carbon::now()->subDays(90)->addSecond(),
        ]);

        $this->artisan('payments:cleanup-batches')->assertSuccessful();

        $this->assertModelExists($batch);
    }
}

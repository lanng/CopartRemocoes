<?php

namespace Tests\Feature\Models;

use App\Enums\PaymentBatchStatusEnum;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\PaymentBatchRun;
use App\Models\Register;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_batch_models_enforce_windows_snapshots_and_relationships(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create(['value' => '123.45']);
        $batch = PaymentBatch::factory()->create([
            'status' => PaymentBatchStatusEnum::PENDING,
            'window_start' => '2026-08-04',
            'window_end' => '2026-08-10',
            'total_amount' => '123.45',
            'confirmed_by' => $user->id,
        ]);
        $item = PaymentBatchItem::factory()->create([
            'payment_batch_id' => $batch->id,
            'register_id' => $register->id,
            'amount' => '123.45',
            'delivery_confirmed_at' => Carbon::parse('2026-08-08 14:00:00'),
        ]);

        $this->assertSame('123.45', $batch->refresh()->total_amount);
        $this->assertSame('123.45', $item->refresh()->amount);
        $this->assertSame($user->id, $batch->confirmer->id);
        $this->assertTrue($batch->items->contains($item));
        $this->assertTrue($register->paymentBatchItems->contains($item));

        $this->expectException(QueryException::class);
        PaymentBatchItem::factory()->create(['register_id' => $register->id]);
    }

    public function test_deleting_a_batch_cascades_to_its_items(): void
    {
        $item = PaymentBatchItem::factory()->create();

        $item->batch->delete();

        $this->assertDatabaseMissing('payment_batch_items', ['id' => $item->id]);
    }

    public function test_a_window_has_one_checkpoint_and_one_batch(): void
    {
        PaymentBatchRun::factory()->create(['window_start' => '2026-08-04', 'window_end' => '2026-08-10']);
        PaymentBatch::factory()->create(['window_start' => '2026-08-04', 'window_end' => '2026-08-10']);

        $this->expectException(QueryException::class);
        PaymentBatchRun::factory()->create(['window_start' => '2026-08-04', 'window_end' => '2026-08-10']);
    }
}

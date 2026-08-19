<?php

namespace Tests\Feature\Services;

use App\Enums\PaymentBatchStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\Register;
use App\Services\Payments\RemovePaymentBatchItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RemovePaymentBatchItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_a_pending_item_requeues_the_register_and_recalculates_the_batch(): void
    {
        $batch = PaymentBatch::factory()->create([
            'status' => PaymentBatchStatusEnum::PENDING,
            'total_amount' => '300.00',
        ]);
        $register = Register::factory()->create([
            'status' => RegisterStatusEnum::DELIVERED,
            'value' => '100.00',
        ]);
        $item = PaymentBatchItem::factory()->create([
            'payment_batch_id' => $batch->id,
            'register_id' => $register->id,
            'amount' => '100.00',
        ]);
        $remainingRegister = Register::factory()->create(['status' => RegisterStatusEnum::DELIVERED]);
        PaymentBatchItem::factory()->create([
            'payment_batch_id' => $batch->id,
            'register_id' => $remainingRegister->id,
            'amount' => '200.00',
        ]);

        app(RemovePaymentBatchItem::class)->handle($item);

        $this->assertModelMissing($item);
        $this->assertSame(RegisterStatusEnum::DELIVERED, $register->refresh()->status);
        $this->assertNotNull($register->payment_deferred_at);
        $this->assertSame('200.00', $batch->refresh()->total_amount);
    }

    public function test_it_deletes_a_pending_batch_when_removing_its_last_item(): void
    {
        $batch = PaymentBatch::factory()->create(['status' => PaymentBatchStatusEnum::PENDING]);
        $register = Register::factory()->create(['status' => RegisterStatusEnum::DELIVERED]);
        $item = PaymentBatchItem::factory()->create([
            'payment_batch_id' => $batch->id,
            'register_id' => $register->id,
        ]);

        app(RemovePaymentBatchItem::class)->handle($item);

        $this->assertModelMissing($item);
        $this->assertModelMissing($batch);
        $this->assertNotNull(Register::find($item->register_id)?->payment_deferred_at);
    }

    public function test_it_rejects_removing_an_item_from_a_confirmed_batch(): void
    {
        $batch = PaymentBatch::factory()->create(['status' => PaymentBatchStatusEnum::CONFIRMED]);
        $item = PaymentBatchItem::factory()->create(['payment_batch_id' => $batch->id]);

        $this->expectException(ValidationException::class);

        app(RemovePaymentBatchItem::class)->handle($item);
    }

    public function test_it_rejects_removing_an_item_when_the_register_is_not_delivered(): void
    {
        $batch = PaymentBatch::factory()->create(['status' => PaymentBatchStatusEnum::PENDING]);
        $register = Register::factory()->create(['status' => RegisterStatusEnum::PAID]);
        $item = PaymentBatchItem::factory()->create([
            'payment_batch_id' => $batch->id,
            'register_id' => $register->id,
        ]);

        $this->expectException(ValidationException::class);

        app(RemovePaymentBatchItem::class)->handle($item);
    }
}

<?php

namespace Tests\Feature\Services;

use App\Enums\PaymentBatchStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\Register;
use App\Models\User;
use App\Services\Payments\ConfirmPaymentBatch;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmPaymentBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_all_items_paid_and_records_the_confirming_user(): void
    {
        $user = User::factory()->create();
        $batch = PaymentBatch::factory()->create();
        $first = Register::factory()->create(['status' => RegisterStatusEnum::DELIVERED]);
        $second = Register::factory()->create(['status' => RegisterStatusEnum::DELIVERED]);
        PaymentBatchItem::factory()->create(['payment_batch_id' => $batch->id, 'register_id' => $first->id]);
        PaymentBatchItem::factory()->create(['payment_batch_id' => $batch->id, 'register_id' => $second->id]);

        app(ConfirmPaymentBatch::class)->handle($batch, $user);

        $this->assertSame(RegisterStatusEnum::PAID, $first->refresh()->status);
        $this->assertSame(RegisterStatusEnum::PAID, $second->refresh()->status);
        $this->assertSame(PaymentBatchStatusEnum::CONFIRMED, $batch->refresh()->status);
        $this->assertSame($user->id, $batch->confirmed_by);
        $this->assertNotNull($batch->confirmed_at);
    }

    public function test_changed_status_blocks_confirmation_without_partial_updates(): void
    {
        $user = User::factory()->create();
        $batch = PaymentBatch::factory()->create();
        $valid = Register::factory()->create(['status' => RegisterStatusEnum::DELIVERED]);
        $changed = Register::factory()->create(['status' => RegisterStatusEnum::COLLECTED]);
        PaymentBatchItem::factory()->create(['payment_batch_id' => $batch->id, 'register_id' => $valid->id]);
        PaymentBatchItem::factory()->create(['payment_batch_id' => $batch->id, 'register_id' => $changed->id]);

        $this->expectException(DomainException::class);
        app(ConfirmPaymentBatch::class)->handle($batch, $user);

        $this->assertSame(RegisterStatusEnum::DELIVERED, $valid->refresh()->status);
        $this->assertSame(PaymentBatchStatusEnum::PENDING, $batch->refresh()->status);
    }

    public function test_confirmed_batches_cannot_be_confirmed_again(): void
    {
        $batch = PaymentBatch::factory()->create(['status' => PaymentBatchStatusEnum::CONFIRMED]);

        $this->expectException(DomainException::class);
        app(ConfirmPaymentBatch::class)->handle($batch, User::factory()->create());
    }
}

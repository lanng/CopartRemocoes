<?php

namespace Database\Factories;

use App\Enums\PaymentBatchStatusEnum;
use App\Models\PaymentBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentBatch> */
class PaymentBatchFactory extends Factory
{
    protected $model = PaymentBatch::class;

    public function definition(): array
    {
        return [
            'status' => PaymentBatchStatusEnum::PENDING,
            'window_start' => '2026-08-04',
            'window_end' => '2026-08-10',
            'generated_at' => now(),
            'total_amount' => '750.00',
            'outlook_sync_failed' => false,
            'outlook_sync_error' => null,
            'confirmed_by' => null,
            'confirmed_at' => null,
        ];
    }
}

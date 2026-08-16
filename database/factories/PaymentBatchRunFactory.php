<?php

namespace Database\Factories;

use App\Models\PaymentBatchRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentBatchRun> */
class PaymentBatchRunFactory extends Factory
{
    protected $model = PaymentBatchRun::class;

    public function definition(): array
    {
        return [
            'window_start' => '2026-08-04',
            'window_end' => '2026-08-10',
            'processed_at' => now(),
            'result' => 'created',
            'item_count' => 1,
            'outlook_sync_failed' => false,
            'outlook_sync_error' => null,
        ];
    }
}

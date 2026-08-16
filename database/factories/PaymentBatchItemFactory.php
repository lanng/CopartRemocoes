<?php

namespace Database\Factories;

use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\Register;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentBatchItem> */
class PaymentBatchItemFactory extends Factory
{
    protected $model = PaymentBatchItem::class;

    public function definition(): array
    {
        return [
            'payment_batch_id' => PaymentBatch::factory(),
            'register_id' => Register::factory(),
            'vehicle_plate' => 'ABC1234',
            'amount' => '750.00',
            'cte_number' => null,
            'delivery_confirmed_at' => now(),
        ];
    }
}

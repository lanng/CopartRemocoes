<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Register>
 */
class RegisterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company' => 'copart',
            'vehicle_model' => 'CIVIC',
            'vehicle_plate' => fake()->unique()->bothify('???####'),
            'origin_city' => 'Ourinhos',
            'destination_city' => 'Pirapora',
            'deadline_withdraw' => now()->addDays(2)->toDateString(),
            'deadline_delivery' => now()->addDays(5)->toDateString(),
            'vehicle_id' => fake()->unique()->numerify('######'),
            'value' => '750.00',
            'status' => 'pending',
            'pdf_sha256' => null,
            'consignor_letter_path' => null,
            'consignor_letter_sha256' => null,
            'insurance' => 'ALLIANZ SEGUROS SA',
            'fipe_value' => '43897.00',
            'payment_code' => 'T691299',
        ];
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MdfeNumberSequence>
 */
class MdfeNumberSequenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cnpj' => '12563112000130',
            'series' => 1,
            'tp_amb' => 2,
            'next_number' => 900000001,
        ];
    }
}

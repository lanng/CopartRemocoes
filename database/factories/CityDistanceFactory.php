<?php

namespace Database\Factories;

use App\Models\CityDistance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CityDistance>
 */
class CityDistanceFactory extends Factory
{
    protected $model = CityDistance::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'origin_ibge' => fake()->unique()->numerify('#######'),
            'destination_ibge' => fake()->unique()->numerify('#######'),
            'km' => fake()->numberBetween(50, 1200),
            'fetched_at' => now(),
        ];
    }
}

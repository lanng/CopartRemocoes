<?php

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ibge_code' => fake()->unique()->numerify('#######'),
            'name' => fake()->city(),
            'state' => fake()->randomElement(['SP', 'MG', 'RJ', 'PR', 'GO']),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
        ];
    }

    public function withoutCoordinates(): static
    {
        return $this->state(fn (): array => [
            'latitude' => null,
            'longitude' => null,
        ]);
    }
}

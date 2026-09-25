<?php

namespace Database\Factories;

use App\Models\CiotPayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CiotPayer>
 */
class CiotPayerFactory extends Factory
{
    protected $model = CiotPayer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'cnpj' => fake()->numerify('##############'),
            'city' => fake()->city(),
            'state' => fake()->randomLetter().fake()->randomLetter(),
            'zipcode' => fake()->numerify('########'),
            'ibge_code' => fake()->numerify('#######'),
            'is_active' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

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
            'ie' => fake()->numerify('#########'),
            'street' => fake()->streetName(),
            'number' => fake()->numerify('###'),
            'district' => fake()->citySuffix(),
            'city' => fake()->city(),
            'state' => fake()->randomLetter().fake()->randomLetter(),
            'zipcode' => fake()->numerify('########'),
            'ibge_code' => fake()->numerify('#######'),
            'is_active' => true,
        ];
    }
}

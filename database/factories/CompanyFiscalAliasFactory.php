<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\CompanyFiscalAlias> */
class CompanyFiscalAliasFactory extends Factory
{
    public function definition(): array
    {
        return [
            'role' => 'sender',
            'source_name' => fake()->unique()->company(),
            'company_id' => Company::factory(),
            'city_id' => null,
            'created_by' => null,
        ];
    }
}

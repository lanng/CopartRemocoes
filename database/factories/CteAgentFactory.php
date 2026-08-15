<?php

namespace Database\Factories;

use App\Models\CteAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CteAgent>
 */
class CteAgentFactory extends Factory
{
    protected $model = CteAgent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'CT-e Agent',
            'hostname' => fake()->unique()->domainWord().'-pc',
            'version' => '1.0.0',
            'capabilities' => ['lab-ui', 'xml-read'],
            'is_dry_run' => true,
            'is_active' => true,
            'last_seen_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}

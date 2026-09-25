<?php

namespace Database\Factories;

use App\Models\CiotVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CiotVehicle>
 */
class CiotVehicleFactory extends Factory
{
    protected $model = CiotVehicle::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plate' => strtoupper(fake()->regexify('[A-Z]{3}[0-9][A-Z0-9][0-9]{2}')),
            'rntrc' => '045963122',
            'axles' => fake()->numberBetween(2, 4),
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
            'line' => null,
            'is_active' => true,
        ];
    }

    public function forRemoval(): static
    {
        return $this->state(fn (): array => ['line' => 'vehicle_removal']);
    }

    public function forTank(): static
    {
        return $this->state(fn (): array => ['line' => 'tank_alcohol']);
    }

    public function trailer(): static
    {
        return $this->state(fn (): array => [
            'type' => CiotVehicle::TYPE_TRAILER,
            'axles' => fake()->numberBetween(1, 4),
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CteDocument>
 */
class CteDocumentFactory extends Factory
{
    protected $model = CteDocument::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => fake()->uuid(),
            'cte_emission_batch_id' => CteEmissionBatch::factory(),
            'register_id' => Register::factory(),
            'status' => CteDocumentStatusEnum::QUEUED,
            'snapshot' => [
                'vehicle_id' => '1146609',
                'vehicle_plate' => 'ESN4A20',
                'payment_code' => 'T691299',
            ],
            'idempotency_key' => fake()->uuid(),
            'execution_mode' => 'dry_run',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteEmissionBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CteEmissionBatch>
 */
class CteEmissionBatchFactory extends Factory
{
    protected $model = CteEmissionBatch::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => CteEmissionBatchStatusEnum::DRAFT,
            'execution_mode' => 'dry_run',
            'created_by' => \App\Models\User::factory(),
        ];
    }
}

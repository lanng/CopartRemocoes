<?php

namespace Database\Factories;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MdfeDocument>
 */
class MdfeDocumentFactory extends Factory
{
    protected $model = MdfeDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => fake()->uuid(),
            'cte_emission_batch_id' => CteEmissionBatch::factory(),
            'status' => CteDocumentStatusEnum::QUEUED,
            'snapshot' => [
                'schema_version' => 1,
                'trip_id' => 1,
                'company' => 'copart',
                'origin_city' => 'Osvaldo Cruz',
                'destination_city' => 'Caçapava',
                'driver_code' => '8',
                'vehicle_code' => '12',
                'ciot' => '520032956638',
                'cargo_value' => '10000.00',
                'cargo_weight_kg' => '2000.00',
                'origin_cep' => '17700000',
                'destination_cep' => '12286140',
                'payment_doc' => '14517191000925',
                'payment_name' => 'Copart Caçapava',
                'payment_value' => '500.00',
                'payment_bank' => '104',
                'payment_agency' => '0073',
                'cte_access_keys' => [
                    '35260912563112000130570010000028171839975374',
                ],
            ],
            'idempotency_key' => fake()->uuid(),
            'execution_mode' => 'dry_run',
        ];
    }
}

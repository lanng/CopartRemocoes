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
            'emitter' => 'direct',
            'snapshot' => [
                'schema_version' => 1,
                'trip_id' => 1,
                'line' => 'vehicle_removal',
                'issue_at' => '2026-10-01T12:00:00-03:00',
                'tp_emit' => '1',
                'ciot' => '520032956638',
                'ciot_source' => 'issued',
                'ciot_responsible_cnpj' => '14517191000925',
                'rntrc' => '45963122',
                'origin' => [
                    'ibge' => '3534609',
                    'name' => 'Osvaldo Cruz',
                    'uf' => 'SP',
                    'cep' => '17700000',
                ],
                'destination' => [
                    'ibge' => '3508504',
                    'name' => 'Caçapava',
                    'uf' => 'SP',
                    'cep' => '12286140',
                ],
                'loading_cities' => [['ibge' => '3534609', 'name' => 'Osvaldo Cruz']],
                'route_ufs' => ['SP'],
                'destinations' => [[
                    'ibge' => '3508504',
                    'name' => 'Caçapava',
                    'cte_access_keys' => ['35260912563112000130570010000028171839975374'],
                ]],
                'vehicle' => [
                    'plate' => 'ABC1234',
                    'uf' => 'SP',
                    'tare_kg' => 5500,
                    'type_rod' => '06',
                    'body_type' => '00',
                    'drivers' => [['name' => 'Motorista de teste', 'cpf' => '12345678901']],
                    'trailers' => [],
                ],
                'inf_lotacao' => null,
                'payment' => [
                    'payer_name' => 'Copart Caçapava',
                    'payer_cnpj' => '14517191000925',
                    'contract_value' => '500.00',
                    'method' => '0',
                    'bank_code' => '104',
                    'bank_agency' => '0073',
                    'components' => [['type' => '01', 'value' => '500.00']],
                ],
                'cargo' => [
                    'value' => '10000.00',
                    'weight_kg' => '2000.0000',
                    'predominant_product' => 'VEICULOS',
                    'type' => '05',
                    'ncm' => '87033210',
                ],
                'insurance' => [
                    'insurer_name' => 'Seguradora Fixture',
                    'insurer_cnpj' => '11111111111111',
                    'policy_number' => 'APOLICE-HML',
                    'endorsements' => ['APOLICE-HML'],
                ],
            ],
            'idempotency_key' => fake()->uuid(),
            'execution_mode' => 'dry_run',
        ];
    }
}

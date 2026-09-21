<?php

namespace Database\Factories;

use App\Enums\CiotLineEnum;
use App\Enums\CiotOperationTypeEnum;
use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use App\Models\CiotPayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Ciot>
 */
class CiotFactory extends Factory
{
    protected $model = Ciot::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payer = CiotPayer::factory()->create();

        return [
            'public_id' => fake()->uuid(),
            'status' => CiotStatusEnum::DRAFT,
            'line' => CiotLineEnum::VehicleRemoval,
            'operation_type' => CiotOperationTypeEnum::Lotation,
            'payer_id' => $payer->id,
            'payer_cnpj' => $payer->cnpj,
            'payer_name' => $payer->name,
            'delivery_payer_id' => $payer->id,
            'delivery_payer_cnpj' => $payer->cnpj,
            'delivery_payer_name' => $payer->name,
            'additional_payers' => [],
            'origin' => ['cidade' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => '17700000', 'ibge' => '3534609'],
            'destination' => ['cidade' => 'Caçapava', 'uf' => 'SP', 'cep' => '12286140', 'ibge' => '3508504'],
            'distance_km' => '716.00',
            'freight_value_cents' => 100000,
            'cargo_weight_kg' => '20000.00',
            'vehicles' => [
                ['placa' => 'PUC8E55', 'rntrc' => '045963122', 'eixos' => 3, 'tipo' => 'automotor'],
            ],
            'travel_start_at' => now()->addDay(),
            'travel_end_at' => now()->addDays(2),
        ];
    }

    public function issued(): static
    {
        return $this->state(fn (): array => [
            'status' => CiotStatusEnum::ISSUED,
            'id_operacao_transporte' => fake()->unique()->numerify('############'),
            'ciot_number' => fake()->unique()->numerify('############'),
            'verifier_code' => fake()->numerify('####'),
            'protocol' => fake()->unique()->numerify('################'),
            'issued_at' => now(),
        ]);
    }

    public function tankAlcohol(): static
    {
        return $this->state(fn (): array => [
            'line' => CiotLineEnum::TankAlcohol,
        ]);
    }
}

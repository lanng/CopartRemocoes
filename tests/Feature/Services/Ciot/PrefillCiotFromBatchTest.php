<?php

namespace Tests\Feature\Services\Ciot;

use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\Ciot;
use App\Models\CiotPayer;
use App\Models\CiotVehicle;
use App\Models\City;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Services\Ciot\PrefillCiotFromBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrefillCiotFromBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ciot.removal.weight_per_vehicle_kg' => 2000,
            'ciot.distance.api_key' => null,
        ]);

        City::factory()->create([
            'ibge_code' => '3534609', 'name' => 'Osvaldo Cruz', 'state' => 'SP',
            'latitude' => -21.7972, 'longitude' => -50.9736,
        ]);
        City::factory()->create([
            'ibge_code' => '3506003', 'name' => 'Bauru', 'state' => 'SP',
            'latitude' => -22.3145, 'longitude' => -49.0606,
        ]);
        City::factory()->create([
            'ibge_code' => '3508504', 'name' => 'Caçapava', 'state' => 'SP',
            'latitude' => -23.1006, 'longitude' => -45.6911,
        ]);
        City::factory()->create([
            'ibge_code' => '3525102', 'name' => 'Jardinópolis', 'state' => 'SP',
            'latitude' => -21.0178, 'longitude' => -47.7639,
        ]);

        $cacapava = CiotPayer::factory()->create([
            'name' => 'Copart Caçapava', 'cnpj' => '14517191000925',
            'city' => 'Caçapava', 'state' => 'SP', 'ibge_code' => '3508504',
        ]);
        CiotPayer::factory()->create([
            'name' => 'AVANT', 'cnpj' => '48580847000208',
            'city' => 'Jardinópolis', 'state' => 'SP', 'ibge_code' => '3525102',
        ]);

        CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);
        CiotVehicle::factory()->forRemoval()->trailer()->create(['plate' => 'TIX7D32', 'axles' => 2]);
        CiotVehicle::factory()->forTank()->create(['plate' => 'AAA1A11', 'type' => 'automotor', 'axles' => 5]);
        CiotVehicle::factory()->create(['plate' => 'SEM1LIN', 'type' => 'reboque', 'axles' => 2]);

        // Distâncias (cache) controlando a maior rota: Osvaldo Cruz → Caçapava.
        foreach ([
            ['3534609', '3508504', 716],
            ['3506003', '3508504', 300],
            ['3534609', '3525102', 250],
            ['3506003', '3525102', 60],
        ] as [$origin, $destination, $km]) {
            \App\Models\CityDistance::query()->create([
                'origin_ibge' => $origin,
                'destination_ibge' => $destination,
                'km' => $km,
                'fetched_at' => now(),
            ]);
        }

        $this->batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);

        CteDocument::factory()->count(3)->create([
            'cte_emission_batch_id' => $this->batch->id,
            'snapshot' => [
                'company' => 'copart',
                'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz',
                'destination_city' => 'Caçapava',
                'value' => '500.00',
                'fipe_value' => '10000.00',
            ],
        ]);

        CteDocument::factory()->create([
            'cte_emission_batch_id' => $this->batch->id,
            'snapshot' => [
                'company' => 'copart',
                'vehicle_plate' => 'DEF5678',
                'origin_city' => 'Bauru',
                'destination_city' => 'Jardinópolis',
                'value' => '400.00',
                'fipe_value' => '9000.00',
            ],
        ]);
    }

    public function test_prefills_freight_weight_vehicles_and_the_longest_route(): void
    {
        $prefill = app(PrefillCiotFromBatch::class)->handle($this->batch);

        $form = $prefill['form'];

        $this->assertSame('1900.00', $form['freight_value']);
        $this->assertSame(4 * 2000, $form['cargo_weight_kg']);
        $this->assertSame('vehicle_removal', $form['line']);
        $this->assertSame('fractioned', $form['operation_type']);

        // maior rota: Osvaldo Cruz → Caçapava (716 km)
        $this->assertSame('3534609', $form['origin.ibge']);
        $this->assertSame('Osvaldo Cruz', $form['origin.cidade']);
        $this->assertSame('SP', $form['origin.uf']);
        $this->assertSame(716, $form['distance_km']);
        $this->assertSame('Caçapava', $form['destination']['cidade']);
        $this->assertSame(['48580847000208'], $form['additional_payers']);

        // composição da linha de remoção: os 2 da remoção + o sem linha definida
        // (disponível para todas); o caminhão de tanque fica de fora.
        $this->assertCount(3, $form['vehicle_ids']);

        $this->assertStringContainsString('Osvaldo Cruz → Copart Caçapava: 716 km', $prefill['ranking']);
    }

    public function test_single_patio_prefills_lotation(): void
    {
        $this->batch->documents->each(fn ($doc) => $doc->update([
            'snapshot' => array_merge($doc->snapshot, ['destination_city' => 'Caçapava']),
        ]));

        $prefill = app(PrefillCiotFromBatch::class)->handle($this->batch);

        $this->assertSame('lotation', $prefill['form']['operation_type']);
        $this->assertSame([], $prefill['form']['additional_payers']);
    }

    public function test_warns_when_an_origin_city_is_unknown(): void
    {
        $this->batch->documents->first()->update([
            'snapshot' => array_merge(
                $this->batch->documents->first()->snapshot,
                ['origin_city' => 'Cidade Inexistente'],
            ),
        ]);

        $prefill = app(PrefillCiotFromBatch::class)->handle($this->batch);

        $this->assertNotEmpty($prefill['warnings']);
        $this->assertStringContainsString('Cidade Inexistente', $prefill['warnings'][0]);
    }

    public function test_guard_blocks_a_second_active_ciot_for_the_batch(): void
    {
        Ciot::factory()->create([
            'cte_emission_batch_id' => $this->batch->id,
            'status' => 'issued',
        ]);

        $this->assertTrue(
            Ciot::query()
                ->where('cte_emission_batch_id', $this->batch->id)
                ->whereNotIn('status', ['canceled'])
                ->exists(),
        );
    }
}

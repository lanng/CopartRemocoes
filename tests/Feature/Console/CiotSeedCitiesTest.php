<?php

namespace Tests\Feature\Console;

use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CiotSeedCitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_municipalities_from_ibge(): void
    {
        Http::fake([
            'https://servicodados.ibge.gov.br/api/v1/localidades/municipios' => Http::response([
                [
                    'id' => 3508504,
                    'nome' => 'Caçapava',
                    'microrregiao' => [
                        'mesorregiao' => [
                            'UF' => ['sigla' => 'SP'],
                        ],
                    ],
                ],
                [
                    'id' => 3534609,
                    'nome' => 'Osvaldo Cruz',
                    'microrregiao' => [
                        'mesorregiao' => [
                            'UF' => ['sigla' => 'SP'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('ciot:seed-cities')->assertSuccessful();

        $this->assertDatabaseHas(City::class, [
            'ibge_code' => '3508504',
            'name' => 'Caçapava',
            'state' => 'SP',
        ]);
        $this->assertDatabaseHas(City::class, [
            'ibge_code' => '3534609',
            'name' => 'Osvaldo Cruz',
            'state' => 'SP',
        ]);
    }

    public function test_is_idempotent(): void
    {
        Http::fake([
            'https://servicodados.ibge.gov.br/api/v1/localidades/municipios' => Http::response([
                [
                    'id' => 3508504,
                    'nome' => 'Caçapava',
                    'microrregiao' => [
                        'mesorregiao' => ['UF' => ['sigla' => 'SP']],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('ciot:seed-cities')->assertSuccessful();
        $this->artisan('ciot:seed-cities')->assertSuccessful();

        $this->assertSame(1, City::query()->count());
    }

    public function test_fails_gracefully_when_ibge_is_unavailable(): void
    {
        Http::fake([
            'https://servicodados.ibge.gov.br/api/v1/localidades/municipios' => Http::response([], 500),
        ]);

        $this->artisan('ciot:seed-cities')->assertFailed();

        $this->assertSame(0, City::query()->count());
    }
}

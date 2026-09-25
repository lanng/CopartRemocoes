<?php

namespace Tests\Feature\Services\Ciot;

use App\Models\City;
use App\Services\Ciot\CepLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CepLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_looks_up_via_brasilapi_and_caches_coordinates(): void
    {
        Http::fake([
            'https://brasilapi.com.br/api/cep/v2/12286140' => Http::response([
                'cep' => '12286140',
                'state' => 'SP',
                'city' => 'Caçapava',
                'location' => [
                    'coordinates' => [
                        'latitude' => '-23.1006',
                        'longitude' => '-45.6911',
                    ],
                ],
            ], 200),
        ]);

        $location = app(CepLookup::class)->lookup('12286-140');

        $this->assertSame('Caçapava', $location['cidade']);
        $this->assertSame('SP', $location['uf']);
        $this->assertNull($location['ibge']);
        $this->assertSame(-23.1006, $location['latitude']);
        $this->assertSame(-45.6911, $location['longitude']);

        $this->assertTrue(collect(Http::recorded())->every(
            fn (array $pair): bool => str_contains($pair[0]->url(), 'brasilapi.com.br'),
        ));
    }

    public function test_caches_the_city_with_coordinates_when_ibge_is_present(): void
    {
        Http::fake([
            'https://brasilapi.com.br/api/cep/v2/12286140' => Http::response([
                'state' => 'SP',
                'city' => 'Caçapava',
                'ibge_code' => '3508504',
                'location' => [
                    'coordinates' => ['latitude' => '-23.1006', 'longitude' => '-45.6911'],
                ],
            ], 200),
        ]);

        app(CepLookup::class)->lookup('12286140');

        $city = City::query()->where('ibge_code', '3508504')->first();

        $this->assertNotNull($city);
        $this->assertSame('Caçapava', $city->name);
        $this->assertSame('SP', $city->state);
        $this->assertTrue($city->hasCoordinates());
    }

    public function test_falls_back_to_viacep_when_brasilapi_fails(): void
    {
        Http::fake([
            'https://brasilapi.com.br/api/cep/v2/17700000' => Http::response([], 404),
            'https://viacep.com.br/ws/17700000/json/' => Http::response([
                'localidade' => 'Osvaldo Cruz',
                'uf' => 'SP',
                'ibge' => '3534609',
            ], 200),
        ]);

        $location = app(CepLookup::class)->lookup('17700000');

        $this->assertSame('Osvaldo Cruz', $location['cidade']);
        $this->assertSame('SP', $location['uf']);
        $this->assertSame('3534609', $location['ibge']);
        $this->assertNull($location['latitude']);
    }

    public function test_returns_null_when_both_providers_fail(): void
    {
        Http::fake([
            'https://brasilapi.com.br/*' => Http::response([], 500),
            'https://viacep.com.br/*' => Http::response(['erro' => true], 200),
        ]);

        $this->assertNull(app(CepLookup::class)->lookup('00000000'));
    }

    public function test_returns_null_without_requests_for_an_invalid_cep(): void
    {
        $this->assertNull(app(CepLookup::class)->lookup('123'));

        Http::assertNothingSent();
    }
}

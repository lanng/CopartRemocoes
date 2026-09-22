<?php

namespace Tests\Feature\Services\Ciot;

use App\Models\City;
use App\Models\CityDistance;
use App\Services\Ciot\CityDistanceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CityDistanceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_zero_for_the_same_city(): void
    {
        $city = City::factory()->create();

        $this->assertSame(0.0, app(CityDistanceCalculator::class)->calculate($city, $city));

        Http::assertNothingSent();
    }

    public function test_returns_the_cached_distance_without_hitting_the_api(): void
    {
        $origin = City::factory()->create();
        $destination = City::factory()->create();

        CityDistance::query()->create([
            'origin_ibge' => $origin->ibge_code,
            'destination_ibge' => $destination->ibge_code,
            'km' => 716.0,
            'fetched_at' => now()->subDays(3),
        ]);

        $km = app(CityDistanceCalculator::class)->calculate($origin, $destination);

        $this->assertSame(716.0, $km);

        Http::assertNothingSent();
    }

    public function test_returns_null_when_coordinates_are_missing(): void
    {
        $origin = City::factory()->withoutCoordinates()->create();
        $destination = City::factory()->create();

        config(['ciot.distance.api_key' => 'test-key']);

        $this->assertNull(app(CityDistanceCalculator::class)->calculate($origin, $destination));
    }

    public function test_returns_null_without_an_api_key(): void
    {
        $origin = City::factory()->create();
        $destination = City::factory()->create();

        $this->assertNull(app(CityDistanceCalculator::class)->calculate($origin, $destination));

        Http::assertNothingSent();
    }

    public function test_calculates_via_openrouteservice_and_caches_the_result(): void
    {
        config(['ciot.distance.api_key' => 'test-key']);

        $origin = City::factory()->create();
        $destination = City::factory()->create();

        Http::fake([
            'https://api.openrouteservice.org/v2/directions/driving-car' => Http::response([
                'routes' => [
                    ['summary' => ['distance' => 716254.8, 'duration' => 32100.0]],
                ],
            ], 200),
        ]);

        $km = app(CityDistanceCalculator::class)->calculate($origin, $destination);

        $this->assertSame(716.3, $km);

        $this->assertDatabaseHas(CityDistance::class, [
            'origin_ibge' => $origin->ibge_code,
            'destination_ibge' => $destination->ibge_code,
            'km' => 716.3,
        ]);

        Http::assertSent(function ($request) use ($origin, $destination): bool {
            return $request->hasHeader('Authorization', 'test-key')
                && $request->data()['coordinates'] === [
                    [$origin->longitude, $origin->latitude],
                    [$destination->longitude, $destination->latitude],
                ];
        });
    }

    public function test_returns_null_when_openrouteservice_fails(): void
    {
        config(['ciot.distance.api_key' => 'test-key']);

        $origin = City::factory()->create();
        $destination = City::factory()->create();

        Http::fake([
            'https://api.openrouteservice.org/v2/directions/driving-car' => Http::response([], 500),
        ]);

        $this->assertNull(app(CityDistanceCalculator::class)->calculate($origin, $destination));

        $this->assertSame(0, CityDistance::query()->count());
    }
}

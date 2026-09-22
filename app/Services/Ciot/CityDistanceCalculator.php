<?php

namespace App\Services\Ciot;

use App\Models\City;
use App\Models\CityDistance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Distância rodoviária entre municípios, em km, com cache local
 * (`city_distances`) e roteamento pelo OpenRouteService.
 */
class CityDistanceCalculator
{
    public function calculate(City $origin, City $destination): ?int
    {
        if ($origin->ibge_code === $destination->ibge_code) {
            return 0;
        }

        $cached = CityDistance::query()
            ->where('origin_ibge', $origin->ibge_code)
            ->where('destination_ibge', $destination->ibge_code)
            ->first();

        if ($cached !== null) {
            return $cached->km;
        }

        $coordinates = [$origin->coordinates(), $destination->coordinates()];

        if (in_array(null, $coordinates, true)) {
            return null;
        }

        $km = $this->fromOpenRouteService($coordinates);

        if ($km === null) {
            return null;
        }

        CityDistance::query()->create([
            'origin_ibge' => $origin->ibge_code,
            'destination_ibge' => $destination->ibge_code,
            'km' => $km,
            'fetched_at' => now(),
        ]);

        return $km;
    }

    /**
     * @param  list<array{float, float}|null>  $coordinates
     */
    protected function fromOpenRouteService(array $coordinates): ?float
    {
        $apiKey = config('ciot.distance.api_key');

        if (blank($apiKey)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => (string) $apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('ciot.distance.timeout'))
                ->post((string) config('ciot.distance.openrouteservice_url'), [
                    'coordinates' => $coordinates,
                ]);
        } catch (Throwable $exception) {
            $this->log('OpenRouteService indisponível', $exception);

            return null;
        }

        if ($response->failed()) {
            $this->log("OpenRouteService respondeu HTTP {$response->status()}");

            return null;
        }

        $distance = $response->json('routes.0.summary.distance');

        if (! is_numeric($distance)) {
            return null;
        }

        return (int) round(((float) $distance) / 1000);
    }

    protected function log(string $message, ?Throwable $exception = null): void
    {
        Log::channel((string) config('ciot.log_channel'))
            ->warning("[ciot] {$message}".($exception !== null ? ': '.$exception->getMessage() : ''));
    }
}

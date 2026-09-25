<?php

namespace App\Services\Ciot;

use App\Models\City;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consulta CEP para preencher cidade/UF/IBGE (e coordenadas quando disponíveis).
 * BrasilAPI CEP v2 (com coordenadas) como primária, ViaCEP como fallback.
 * Falha nunca bloqueia: retorna null e o operador preenche manualmente.
 */
class CepLookup
{
    /**
     * @return array{cidade: string, uf: string, ibge: ?string, latitude: ?float, longitude: ?float}|null
     */
    public function lookup(string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen($cep) !== 8) {
            return null;
        }

        $location = $this->fromBrasilApi($cep) ?? $this->fromViaCep($cep);

        if ($location === null) {
            return null;
        }

        // BrasilAPI v2 não devolve o código IBGE; resolvemos na base local dos
        // municípios do IBGE (ciot:seed-cities) pelo nome + UF.
        if (blank($location['ibge'])) {
            $location['ibge'] = $this->resolveLocalIbge($location['cidade'], $location['uf']);
        }

        if (filled($location['ibge'])) {
            $this->cacheCoordinates(
                $location['ibge'],
                $location['cidade'],
                $location['uf'],
                $location['latitude'],
                $location['longitude'],
            );
        }

        return $location;
    }

    protected function resolveLocalIbge(string $city, string $state): ?string
    {
        $found = City::query()
            ->where('state', $state)
            ->where(fn ($query) => $query
                ->where('name', $city)
                ->orWhere('name', 'like', "{$city}%"))
            ->orderByRaw('case when name = ? then 0 else 1 end', [$city])
            ->first();

        return $found?->ibge_code;
    }

    /**
     * @return array{cidade: string, uf: string, ibge: ?string, latitude: ?float, longitude: ?float}|null
     */
    protected function fromBrasilApi(string $cep): ?array
    {
        try {
            $response = Http::timeout((int) config('ciot.lookup.timeout'))
                ->get(config('ciot.lookup.brasilapi_url').'/'.$cep);
        } catch (Throwable $exception) {
            $this->log('BrasilAPI indisponível', $exception);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $city = (string) ($response->json('city') ?? '');
        $state = (string) ($response->json('state') ?? '');
        $ibge = $response->json('location.coordinates.ibge_code')
            ?? $response->json('ibge_code')
            ?? null;

        if ($city === '' || $state === '') {
            return null;
        }

        $latitude = $response->json('location.coordinates.latitude');
        $longitude = $response->json('location.coordinates.longitude');

        return [
            'cidade' => $city,
            'uf' => $state,
            'ibge' => filled($ibge) ? (string) $ibge : null,
            'latitude' => is_numeric($latitude) ? (float) $latitude : null,
            'longitude' => is_numeric($longitude) ? (float) $longitude : null,
        ];
    }

    /**
     * @return array{cidade: string, uf: string, ibge: ?string, latitude: ?float, longitude: ?float}|null
     */
    protected function fromViaCep(string $cep): ?array
    {
        try {
            $response = Http::timeout((int) config('ciot.lookup.timeout'))
                ->get(config('ciot.lookup.viacep_url').'/'.$cep.'/json/');
        } catch (Throwable $exception) {
            $this->log('ViaCEP indisponível', $exception);

            return null;
        }

        if ($response->failed() || $response->json('erro')) {
            return null;
        }

        $city = (string) ($response->json('localidade') ?? '');
        $state = (string) ($response->json('uf') ?? '');
        $ibge = $response->json('ibge');

        if ($city === '' || $state === '') {
            return null;
        }

        return [
            'cidade' => $city,
            'uf' => $state,
            'ibge' => filled($ibge) ? (string) $ibge : null,
            'latitude' => null,
            'longitude' => null,
        ];
    }

    /**
     * Grava/atualiza o município no cache local com as coordenadas recebidas.
     */
    protected function cacheCoordinates(mixed $ibge, string $city, string $state, mixed $latitude, mixed $longitude): void
    {
        if (blank($ibge) || ! is_numeric($latitude) || ! is_numeric($longitude)) {
            return;
        }

        City::query()->updateOrCreate(
            ['ibge_code' => (string) $ibge],
            [
                'name' => $city,
                'state' => $state,
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ],
        );
    }

    protected function log(string $message, Throwable $exception): void
    {
        Log::channel((string) config('ciot.log_channel'))
            ->warning("[ciot] {$message}: ".$exception->getMessage());
    }
}

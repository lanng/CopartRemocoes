<?php

namespace App\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Models\CiotPayer;
use App\Models\CiotVehicle;
use App\Models\City;
use App\Models\CteEmissionBatch;
use Illuminate\Support\Collection;

/**
 * Deriva o pré-preenchimento do CIOT a partir de um lote de CT-e (spec, Fase 4):
 * frete = soma dos CT-es; peso = nº de CT-es × peso por veículo; veículos =
 * composição da linha de remoção; rota = matriz de distâncias entre as cidades
 * de origem do lote e os pátios de destino, com a maior rota pré-selecionada
 * (regra operacional), sempre confirmável pelo operador.
 */
class PrefillCiotFromBatch
{
    /**
     * @return array{form: array<string, mixed>, ranking: string, warnings: list<string>, origins: Collection, patios: Collection}
     */
    public function handle(CteEmissionBatch $batch): array
    {
        $warnings = [];

        $documents = $batch->documents;

        $origens = $this->resolveOriginCities(
            $documents->pluck('snapshot.origin_city')->filter()->unique()->values(),
            $warnings,
        );

        $patios = $this->resolveDestinationPatios(
            $documents->pluck('snapshot.destination_city')->filter()->unique()->values(),
            $warnings,
        );

        $pairs = $this->distancePairs($origens, $patios);
        usort($pairs, fn (array $a, array $b): int => $b['km'] <=> $a['km']);
        $best = $pairs[0] ?? null;

        $ranking = collect($pairs)
            ->map(fn (array $pair): string => sprintf('%s → %s: %d km', $pair['origin']->name, $pair['payer']->name, $pair['km']))
            ->implode("\n");

        if ($patios->isEmpty()) {
            $warnings[] = 'Nenhum pátio do cadastro corresponde às cidades de destino deste lote — selecione o pagante e o destinatário manualmente.';
        }

        $form = [
            'line' => CiotLineEnum::VehicleRemoval->value,
            'operation_type' => $patios->count() > 1 ? 'fractioned' : 'lotation',
            'payer_id' => $best['payer']->id ?? null,
            'delivery_payer_id' => $best['payer']->id ?? null,
            'additional_payers' => $patios
                ->reject(fn (CiotPayer $patio): bool => $patio->id === ($best['payer']->id ?? null))
                ->pluck('cnpj')
                ->values()
                ->all(),
            'origin.ibge' => $best['origin']->ibge_code ?? null,
            'origin.cidade' => $best['origin']->name ?? null,
            'origin.uf' => $best['origin']->state ?? null,
            'destination' => ($best['payer'] ?? null)?->location(),
            'distance_km' => $best['km'] ?? null,
            'freight_value' => number_format($batch->totalTransportValueInCents() / 100, 2, '.', ''),
            'cargo_weight_kg' => $documents->count() * max(1, (int) config('ciot.removal.weight_per_vehicle_kg')),
            'vehicle_ids' => $this->lineVehicles()->pluck('id')->all(),
            'travel_start_at' => today(),
            'travel_end_at' => today()->addDay(),
        ];

        return [
            'form' => $form,
            'ranking' => $ranking,
            'warnings' => $warnings,
            'origins' => $origens,
            'patios' => $patios,
        ];
    }

    /**
     * Composição da linha de remoção: veículos ativos da linha (ou os sem linha
     * definida, disponíveis para todas).
     *
     * @return Collection<int, CiotVehicle>
     */
    public function lineVehicles(): Collection
    {
        return CiotVehicle::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('line', CiotLineEnum::VehicleRemoval->value)
                ->orWhereNull('line'))
            ->orderBy('type')
            ->orderBy('plate')
            ->get();
    }

    /**
     * @param  Collection<int, City>  $origens
     * @param  Collection<int, CiotPayer>  $patios
     * @return list<array{origin: City, payer: CiotPayer, km: int}>
     */
    protected function distancePairs(Collection $origens, Collection $patios): array
    {
        $pairs = [];

        foreach ($origens as $origin) {
            foreach ($patios as $patio) {
                $destinationCity = filled($patio->ibge_code)
                    ? City::query()->where('ibge_code', $patio->ibge_code)->first()
                    : null;

                if ($destinationCity === null || ! $origin->hasCoordinates() || ! $destinationCity->hasCoordinates()) {
                    continue;
                }

                $km = app(CityDistanceCalculator::class)->calculate($origin, $destinationCity);

                if ($km !== null) {
                    $pairs[] = ['origin' => $origin, 'payer' => $patio, 'km' => $km];
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  Collection<int, string>  $names
     * @return Collection<int, City>
     */
    protected function resolveOriginCities(Collection $names, array &$warnings): Collection
    {
        $cities = City::query()->orderBy('name')->get();

        return $names
            ->map(function (string $name) use ($cities, &$warnings): ?City {
                $found = $this->matchCity($cities, $name);

                if ($found === null) {
                    $warnings[] = "Cidade de origem '{$name}' não encontrada na base IBGE — informe a origem manualmente.";
                }

                return $found;
            })
            ->filter()
            ->unique('ibge_code')
            ->values();
    }

    /**
     * Cruza as cidades de destino do lote com os pagantes cadastrados.
     *
     * @param  Collection<int, string>  $names
     * @return Collection<int, CiotPayer>
     */
    protected function resolveDestinationPatios(Collection $names, array &$warnings): Collection
    {
        return CiotPayer::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (CiotPayer $payer): bool => $names->contains(
                fn (string $name): bool => strcasecmp(trim($name), trim($payer->city)) === 0,
            ))
            ->values();
    }

    protected function matchCity(Collection $cities, string $name): ?City
    {
        $target = $this->normalize($name);

        $matches = $cities->filter(fn (City $city): bool => $this->normalize($city->name) === $target);

        if ($matches->count() > 1) {
            // Mesmo nome em estados diferentes: preferimos a que tem coordenadas.
            $withCoordinates = $matches->filter(fn (City $city): bool => $city->hasCoordinates());

            return ($withCoordinates->isNotEmpty() ? $withCoordinates : $matches)->first();
        }

        return $matches->first();
    }

    protected function normalize(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $transliterated ?: $value) ?? $value));
    }
}

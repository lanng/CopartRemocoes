<?php

namespace App\Console\Commands;

use App\Models\City;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

class CiotSeedCitiesCommand extends Command
{
    protected $signature = 'ciot:seed-cities';

    protected $description = 'Importa os municípios do IBGE (código, nome, UF) para autocomplete e cache de coordenadas';

    public function handle(): int
    {
        $this->info('Baixando municípios do IBGE...');

        try {
            $response = Http::timeout(60)
                ->get('https://servicodados.ibge.gov.br/api/v1/localidades/municipios');
        } catch (Throwable $exception) {
            $this->error('Falha ao consultar o IBGE: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error("IBGE respondeu HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $municipios = $response->json();

        if (! is_array($municipios) || $municipios === []) {
            $this->error('Resposta do IBGE vazia ou inválida.');

            return self::FAILURE;
        }

        $now = now();

        $rows = collect($municipios)
            ->map(function (array $municipio) use ($now): ?array {
                $sigla = $municipio['microrregiao']['mesorregiao']['UF']['sigla'] ?? null;
                $nome = $municipio['nome'] ?? null;
                $codigo = $municipio['id'] ?? null;

                if ($sigla === null || $nome === null || $codigo === null) {
                    return null;
                }

                return [
                    'ibge_code' => (string) $codigo,
                    'name' => (string) $nome,
                    'state' => (string) $sigla,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->filter()
            ->values();

        foreach ($rows->chunk(500) as $chunk) {
            City::upsert($chunk->all(), ['ibge_code'], ['name', 'state', 'updated_at']);

            Sleep::for(100)->milliseconds();
        }

        $this->info("{$rows->count()} municípios sincronizados (".City::count().' na base).');

        return self::SUCCESS;
    }
}

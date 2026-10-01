<?php

namespace App\Services\Cte;

use App\Models\CteNumberSequence;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Alocação atômica do nCT da série do emissor direto (spec §3): incremento
 * sob lockForUpdate garante continuidade sem salto/duplicidade. Em produção a
 * seed inicial é manual no switch do Lab (último nCT + 1, spec §7) — recusar
 * chutar nunca é opcional.
 */
class AllocateCteNumber
{
    public function handle(?string $cnpj = null, ?int $serie = null): int
    {
        $cnpj = (string) ($cnpj ?? config('dfe.emit.cnpj'));
        $serie = (int) ($serie ?? config('dfe.cte.serie'));

        return DB::transaction(function () use ($cnpj, $serie): int {
            $sequence = CteNumberSequence::query()
                ->where('cnpj', $cnpj)
                ->where('serie', $serie)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = CteNumberSequence::query()->create([
                    'cnpj' => $cnpj,
                    'serie' => $serie,
                    'next_number' => $this->initialNumber(),
                ]);
            }

            $number = (int) $sequence->next_number;

            $sequence->forceFill(['next_number' => $number + 1])->save();

            return $number;
        });
    }

    protected function initialNumber(): int
    {
        $configured = config('dfe.numbering_start');

        if (filled($configured)) {
            return (int) $configured;
        }

        // Homologação usa a sequência livre de teste (spec §2.4). Em produção
        // não há default: a seed manual no switch é obrigatória.
        if ((string) config('dfe.tp_amb') === '1') {
            throw new DomainException(
                'Numeração de produção não semeada: defina DFE_CTE_NUMBER_START com o último nCT do Lab + 1 antes de emitir (spec §7).',
            );
        }

        return 900000001;
    }
}

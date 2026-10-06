<?php

namespace App\Services\Dfe;

use App\Models\MdfeNumberSequence;
use DomainException;
use Illuminate\Support\Facades\DB;

class AllocateMdfeNumber
{
    public function handle(?string $cnpj = null, ?int $series = null): int
    {
        $cnpj ??= (string) config('dfe.emit.cnpj');
        $series ??= (int) config('dfe.mdfe.series');

        return DB::transaction(function () use ($cnpj, $series): int {
            $sequence = MdfeNumberSequence::query()
                ->where('cnpj', $cnpj)
                ->where('series', $series)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = MdfeNumberSequence::query()->create([
                    'cnpj' => $cnpj,
                    'series' => $series,
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
        $configured = config('dfe.mdfe.numbering_start');

        if (filled($configured)) {
            return (int) $configured;
        }

        if ((string) config('dfe.tp_amb') === '1') {
            throw new DomainException('Numeração de MDF-e em produção não semeada; informe DFE_MDFE_NUMBER_START com a sequência real após conferir o último número do Lab.');
        }

        return 900000001;
    }
}

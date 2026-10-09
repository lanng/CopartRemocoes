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

        $environment = (string) config('dfe.tp_amb');
        if (! in_array($environment, ['1', '2'], true)) {
            throw new DomainException('DFE_TP_AMB deve ser 1 (produção) ou 2 (homologação).');
        }

        return DB::transaction(function () use ($cnpj, $series, $environment): int {
            if (MdfeNumberSequence::query()->where('cnpj', $cnpj)->where('series', $series)->where('tp_amb', 0)->exists()) {
                throw new DomainException('A sequência antiga de MDF-e está sem ambiente definido; confirme se pertence à produção ou homologação antes de emitir.');
            }

            $configuredStart = $this->configuredStartNumber();
            $sequence = MdfeNumberSequence::query()
                ->where('cnpj', $cnpj)
                ->where('series', $series)
                ->where('tp_amb', $environment)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = MdfeNumberSequence::query()->create([
                    'cnpj' => $cnpj,
                    'series' => $series,
                    'tp_amb' => $environment,
                    'next_number' => $this->initialNumber($configuredStart),
                ]);
            } elseif ($configuredStart !== null && $configuredStart > (int) $sequence->next_number) {
                $sequence->forceFill(['next_number' => $configuredStart])->save();
            }

            $number = (int) $sequence->next_number;
            $sequence->forceFill(['next_number' => $number + 1])->save();

            return $number;
        });
    }

    protected function initialNumber(?int $configuredStart): int
    {
        if ($configuredStart !== null) {
            return $configuredStart;
        }

        if ((string) config('dfe.tp_amb') === '1') {
            throw new DomainException('Numeração de MDF-e em produção não semeada; informe DFE_MDFE_NUMBER_START com a sequência real após conferir o último número do Lab.');
        }

        return 900000001;
    }

    private function configuredStartNumber(): ?int
    {
        $configured = trim((string) config('dfe.mdfe.numbering_start'));

        if ($configured === '') {
            return null;
        }

        if (! preg_match('/^[1-9]\d{0,8}$/', $configured)) {
            throw new DomainException('DFE_MDFE_NUMBER_START deve ser um inteiro entre 1 e 999999999.');
        }

        return (int) $configured;
    }
}

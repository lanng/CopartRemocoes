<?php

namespace App\Services\Cte;

use App\Models\CteNumberSequence;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Alocação atômica do nCT da série do emissor direto (spec §3). O valor do
 * .env funciona como limite mínimo; ele pode avançar a sequência salva, mas
 * nunca a reduz.
 *
 * REGRA DO ORQUESTRADOR — o número queima SEMPRE: a transação abaixo é
 * autocontida e commita antes de assinar/transmitir/gravar; um rollback
 * downstream (ex.: falha de S3 no registro) NUNCA devolve o número. Por isso
 * NÃO chamar este serviço (nem o BuildCteXml) dentro de uma transação
 * externa: um rollback ali devolveria um nCT cujo CT-e pode ter sido
 * autorizado pela SEFAZ — colisão 539 real. Hoje nenhuma chamada aninha
 * (EmitCteDocument::attempt e dfe:smoke rodam fora de transações).
 */
class AllocateCteNumber
{
    public function handle(?string $cnpj = null, ?int $serie = null): int
    {
        $cnpj = (string) ($cnpj ?? config('dfe.emit.cnpj'));
        $serie = (int) ($serie ?? config('dfe.cte.serie'));

        $environment = (string) config('dfe.tp_amb');
        if (! in_array($environment, ['1', '2'], true)) {
            throw new DomainException('DFE_TP_AMB deve ser 1 (produção) ou 2 (homologação).');
        }

        return DB::transaction(function () use ($cnpj, $serie, $environment): int {
            if (CteNumberSequence::query()->where('cnpj', $cnpj)->where('serie', $serie)->where('tp_amb', 0)->exists()) {
                throw new DomainException('A sequência antiga de CT-e está sem ambiente definido; confirme se pertence à produção ou homologação antes de emitir.');
            }

            $configuredStart = $this->configuredStartNumber();
            $sequence = CteNumberSequence::query()
                ->where('cnpj', $cnpj)
                ->where('serie', $serie)
                ->where('tp_amb', $environment)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = CteNumberSequence::query()->create([
                    'cnpj' => $cnpj,
                    'serie' => $serie,
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

        // Homologação usa a sequência livre de teste (spec §2.4). Em produção
        // não há default: a seed manual no switch é obrigatória.
        if ((string) config('dfe.tp_amb') === '1') {
            throw new DomainException(
                'Numeração de produção não semeada: defina DFE_CTE_NUMBER_START com o próximo número fiscal disponível antes de emitir (spec §7).',
            );
        }

        return 900000001;
    }

    private function configuredStartNumber(): ?int
    {
        $configured = trim((string) config('dfe.numbering_start'));

        if ($configured === '') {
            return null;
        }

        if (! preg_match('/^[1-9]\d{0,8}$/', $configured)) {
            throw new DomainException('DFE_CTE_NUMBER_START deve ser um inteiro entre 1 e 999999999.');
        }

        return (int) $configured;
    }
}

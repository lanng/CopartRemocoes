<?php

namespace App\Console\Commands;

use App\Services\Dfe\SefazException;
use App\Services\Dfe\SefazSoapClient;
use Illuminate\Console\Command;
use Throwable;

class DfeStatusCommand extends Command
{
    protected $signature = 'dfe:status';

    protected $description = 'Consulta o StatusServico do CT-e 4.00 na SEFAZ/SP (sucesso = cStat 107)';

    public function handle(SefazSoapClient $client): int
    {
        $ambiente = (string) config('dfe.tp_amb') === '1' ? 'produção' : 'homologação';

        try {
            $response = $client->status();
        } catch (SefazException $exception) {
            $this->error('[SEFAZ] '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('['.class_basename($exception).'] '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($response->isServiceOnline()) {
            $this->info("✔ SEFAZ/SP em operação ({$ambiente}, cStat 107): {$response->xMotivo}");

            return self::SUCCESS;
        }

        $this->error("Status inesperado ({$ambiente}): cStat ".($response->cStat ?? '—').' — '.($response->xMotivo ?? 'sem xMotivo'));

        return self::FAILURE;
    }
}

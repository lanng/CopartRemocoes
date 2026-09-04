<?php

namespace App\Console\Commands;

use App\Services\Payments\GeneratePendingPaymentBatches as Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeneratePendingPaymentBatches extends Command
{
    protected $signature = 'payments:generate-pending-batches';

    protected $description = 'Gera lotes de pagamento pendentes.';

    public function handle(Generator $generator): int
    {
        Log::info('payments:generate-pending-batches: iniciando.');

        try {
            $result = $generator->handle();

            Log::info('payments:generate-pending-batches: concluído.', $result);
            $this->info("{$result['created']} lote(s) criado(s), {$result['empty']} janela(s) vazia(s).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('payments:generate-pending-batches: execução interrompida por exceção.', ['exception' => (string) $e]);

            return self::FAILURE;
        }
    }
}

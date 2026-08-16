<?php

namespace App\Console\Commands;

use App\Services\Payments\GeneratePendingPaymentBatches as Generator;
use Illuminate\Console\Command;

class GeneratePendingPaymentBatches extends Command
{
    protected $signature = 'payments:generate-pending-batches';

    protected $description = 'Gera lotes de pagamento pendentes.';

    public function handle(Generator $generator): int
    {
        $result = $generator->handle();
        $this->info("{$result['created']} lote(s) criado(s), {$result['empty']} janela(s) vazia(s).");

        return self::SUCCESS;
    }
}

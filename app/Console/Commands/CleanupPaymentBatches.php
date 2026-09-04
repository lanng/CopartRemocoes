<?php

namespace App\Console\Commands;

use App\Models\PaymentBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupPaymentBatches extends Command
{
    protected $signature = 'payments:cleanup-batches';

    protected $description = 'Remove lotes de pagamento confirmados há mais de 90 dias.';

    public function handle(): int
    {
        Log::info('payments:cleanup-batches: iniciando.', [
            'retention_days' => config('payment_batches.confirmed_retention_days'),
        ]);

        try {
            $deleted = PaymentBatch::query()
                ->where('status', 'confirmed')
                ->where('confirmed_at', '<=', now()->subDays((int) config('payment_batches.confirmed_retention_days')))
                ->delete();

            Log::info('payments:cleanup-batches: concluído.', ['deleted' => $deleted]);
            $this->info("{$deleted} lote(s) de pagamento removido(s).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('payments:cleanup-batches: execução interrompida por exceção.', ['exception' => (string) $e]);

            return self::FAILURE;
        }
    }
}

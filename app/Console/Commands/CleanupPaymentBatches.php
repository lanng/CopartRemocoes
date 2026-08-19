<?php

namespace App\Console\Commands;

use App\Models\PaymentBatch;
use Illuminate\Console\Command;

class CleanupPaymentBatches extends Command
{
    protected $signature = 'payments:cleanup-batches';

    protected $description = 'Remove lotes de pagamento confirmados há mais de 90 dias.';

    public function handle(): int
    {
        $deleted = PaymentBatch::query()
            ->where('status', 'confirmed')
            ->where('confirmed_at', '<=', now()->subDays((int) config('payment_batches.confirmed_retention_days')))
            ->delete();

        $this->info("{$deleted} lote(s) de pagamento removido(s).");

        return self::SUCCESS;
    }
}

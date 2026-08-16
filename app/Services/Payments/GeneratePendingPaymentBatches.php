<?php

namespace App\Services\Payments;

use App\Services\MicrosoftGraph\SyncChecklistEmailsService;
use Carbon\CarbonImmutable;

class GeneratePendingPaymentBatches
{
    public function __construct(
        private readonly CreatePaymentBatchForWindow $creator,
        private readonly SyncChecklistEmailsService $sync,
    ) {}

    /** @return array{created: int, empty: int, processed: int} */
    public function handle(string $source = 'automatic', ?string $through = null): array
    {
        $syncFailed = false;
        $syncError = null;

        try {
            $this->sync->handle();
        } catch (\Throwable $exception) {
            $syncFailed = true;
            $syncError = $exception->getMessage();
        }

        $throughDate = $through
            ? CarbonImmutable::parse($through, config('payment_batches.timezone'))
            : CarbonImmutable::now(config('payment_batches.timezone'));
        $windows = PaymentBatchWindow::dueWindowsThrough($throughDate, PaymentBatchWindow::fromConfig());
        $created = 0;
        $empty = 0;
        $processed = 0;

        foreach ($windows as $window) {
            $batch = $this->creator->handle($window, $syncFailed, $syncError);
            $processed++;

            if ($batch) {
                $created++;
            } else {
                $empty++;
            }
        }

        return compact('created', 'empty', 'processed');
    }
}

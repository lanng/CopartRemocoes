<?php

namespace App\Console\Commands;

use App\Services\Dfe\BackfillFiscalDocumentLinks as BackfillFiscalDocumentLinksService;
use Illuminate\Console\Command;

class BackfillFiscalDocumentLinks extends Command
{
    protected $signature = 'app:backfill-fiscal-document-links
                            {--batch=* : Batch ID to include; may be repeated}
                            {--from-batch= : First batch ID in an inclusive range}
                            {--to-batch= : Last batch ID in an inclusive range}
                            {--apply : Persist verified links and reservations}';

    protected $description = 'Reconciles legacy CT-e and CIOT links for MDF-e documents';

    public function handle(BackfillFiscalDocumentLinksService $backfill): int
    {
        $batchIds = $this->option('batch');
        $fromBatchId = $this->option('from-batch');
        $toBatchId = $this->option('to-batch');

        if (! is_array($batchIds) || ! $this->validBatchIds($batchIds)
            || ! $this->validRange($fromBatchId, $toBatchId)) {
            $this->error('Batch IDs must be positive integers, and a range requires both --from-batch and --to-batch in ascending order.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $report = $backfill->handle(
            batchIds: array_map('intval', $batchIds),
            fromBatchId: $fromBatchId === null ? null : (int) $fromBatchId,
            toBatchId: $toBatchId === null ? null : (int) $toBatchId,
            apply: $apply,
        );

        $this->line('Mode: '.($apply ? 'apply' : 'dry-run'));
        $this->line("Scanned: {$report['scanned']}");
        $this->line("Resolved: {$report['resolved']}");
        $this->line("Pending: {$report['pending']}");
        $this->line("Divergent: {$report['divergent']}");

        foreach ($report['items'] as $item) {
            $reasonText = $item['reasons'] === [] ? '' : ' ('.implode(', ', $item['reasons']).')';
            $this->line("MDF-e #{$item['mdfe_document_id']}: {$item['status']}{$reasonText}");
        }

        return self::SUCCESS;
    }

    /** @param array<int, mixed> $batchIds */
    private function validBatchIds(array $batchIds): bool
    {
        foreach ($batchIds as $batchId) {
            if (! is_string($batchId) && ! is_int($batchId)) {
                return false;
            }

            if (filter_var($batchId, FILTER_VALIDATE_INT) === false || (int) $batchId < 1) {
                return false;
            }
        }

        return true;
    }

    private function validRange(mixed $fromBatchId, mixed $toBatchId): bool
    {
        if ($fromBatchId === null && $toBatchId === null) {
            return true;
        }

        if (filter_var($fromBatchId, FILTER_VALIDATE_INT) === false
            || filter_var($toBatchId, FILTER_VALIDATE_INT) === false) {
            return false;
        }

        return (int) $fromBatchId > 0 && (int) $toBatchId >= (int) $fromBatchId;
    }
}

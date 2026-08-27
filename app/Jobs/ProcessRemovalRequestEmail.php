<?php

namespace App\Jobs;

use App\Models\IntegrationInboxItem;
use App\Models\MicrosoftGraphConnection;
use App\Services\MicrosoftGraph\RemovalRequests\AttachConsignorLetterToRegister;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestImporter;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestPdfPreparer;
use DomainException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessRemovalRequestEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $integrationInboxItemId) {}

    public function uniqueId(): string
    {
        return (string) $this->integrationInboxItemId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('removal-request:'.$this->integrationInboxItemId))
                ->releaseAfter(30)
                ->expireAfter(180),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        RemovalRequestPdfPreparer $preparer,
        RemovalRequestImporter $importer,
        ?AttachConsignorLetterToRegister $consignorLetterAttacher = null,
    ): void {
        $item = DB::transaction(function (): ?IntegrationInboxItem {
            $item = IntegrationInboxItem::query()
                ->lockForUpdate()
                ->find($this->integrationInboxItemId);

            if ($item === null || $this->isTerminal($item->status) || ! in_array($item->status, ['queued', 'processing'], true)) {
                return null;
            }

            $item->forceFill([
                'status' => 'processing',
                'failure_reason' => null,
                'resolved_at' => null,
            ])->save();

            return $item->refresh();
        });

        if ($item === null) {
            return;
        }

        $connection = MicrosoftGraphConnection::query()->where('is_active', true)->first();

        if ($connection === null) {
            $this->markPending('graph_connection_missing');

            return;
        }

        $pdf = null;

        try {
            $pdf = $preparer->prepare(
                $connection,
                $item->external_id,
                (string) $item->extracted_vehicle_plate,
            );
            $item = $importer->handle($item, $pdf);
            ($consignorLetterAttacher ?? app(AttachConsignorLetterToRegister::class))->handle($item, $connection);
        } catch (DomainException) {
            $this->markPending('domain_error');
        } finally {
            if ($pdf !== null) {
                @unlink($pdf->temporaryPath);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function (): void {
            $item = IntegrationInboxItem::query()
                ->lockForUpdate()
                ->find($this->integrationInboxItemId);

            if ($item === null || ! in_array($item->status, ['queued', 'processing'], true)) {
                return;
            }

            $item->forceFill([
                'status' => 'pending',
                'failure_reason' => 'processing_failed',
                'resolved_at' => null,
            ])->save();
        });
    }

    private function markPending(string $failureReason): void
    {
        IntegrationInboxItem::query()
            ->whereKey($this->integrationInboxItemId)
            ->where('status', 'processing')
            ->update([
                'status' => 'pending',
                'failure_reason' => $failureReason,
                'resolved_at' => null,
            ]);
    }

    private function isTerminal(string $status): bool
    {
        return in_array($status, ['processed', 'no_changes', 'alert', 'rejected'], true);
    }
}

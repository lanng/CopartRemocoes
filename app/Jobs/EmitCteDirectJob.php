<?php

namespace App\Jobs;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteDocument;
use App\Services\Cte\CteBatchStatusUpdater;
use App\Services\Cte\DirectCteDocumentWorkflow;
use App\Services\Cte\EmitCteDocument;
use DomainException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Espelho do EmitCiotJob para a emissão direta (spec dfe §4.1): um job por
 * documento, unique, retries com backoff; falhas terminais vão para
 * failed_before_authorization sem queimar as tentativas.
 */
class EmitCteDirectJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $cteDocumentId) {}

    public function uniqueId(): string
    {
        return (string) $this->cteDocumentId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('cte-direct-emission:'.$this->cteDocumentId))
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(EmitCteDocument $emitter): void
    {
        $document = CteDocument::query()->find($this->cteDocumentId);

        if ($document === null) {
            return;
        }

        if (in_array($document->status, [
            CteDocumentStatusEnum::AUTHORIZED,
            CteDocumentStatusEnum::REJECTED,
            CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
            CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        ], true)) {
            return;
        }

        try {
            $emitter->attempt($document);
        } catch (DomainException $exception) {
            $this->failDocument($document, $exception->getMessage());
        }
    }

    public function failed(Throwable $exception): void
    {
        $document = CteDocument::query()->find($this->cteDocumentId);

        if ($document !== null) {
            $this->failDocument($document, $exception->getMessage(), 'job_exhausted');
        }
    }

    protected function failDocument(CteDocument $document, string $message, ?string $errorCode = null): void
    {
        try {
            app(DirectCteDocumentWorkflow::class)->transition($document, CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION);

            $document->forceFill([
                'error_stage' => 'transmission',
                'error_code' => $errorCode,
                'error_message' => $message,
            ])->save();

            app(CteBatchStatusUpdater::class)->handle($document->batch);
        } catch (DomainException $transitionException) {
            Log::channel((string) config('dfe.log_channel'))->warning('Falha ao marcar CT-e como failed_before_authorization.', [
                'document' => $document->public_id,
                'status' => $document->status->value,
                'reason' => $transitionException->getMessage(),
            ]);
        }
    }
}

<?php

namespace App\Jobs;

use App\Enums\CteDocumentStatusEnum;
use App\Models\MdfeDocument;
use App\Services\Cte\DirectMdfeDocumentWorkflow;
use App\Services\Cte\EmitMdfeDocument;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmitMdfeDirectJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $mdfeDocumentId) {}

    public function uniqueId(): string
    {
        return (string) $this->mdfeDocumentId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('mdfe-direct-emission:'.$this->mdfeDocumentId))->releaseAfter(30)->expireAfter(600)];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(EmitMdfeDocument $emitter): void
    {
        $document = MdfeDocument::query()->find($this->mdfeDocumentId);

        if ($document === null || in_array($document->status, [
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
        $document = MdfeDocument::query()->find($this->mdfeDocumentId);

        if ($document !== null) {
            $this->failDocument($document, $exception->getMessage(), 'job_exhausted');
        }
    }

    protected function failDocument(MdfeDocument $document, string $message, ?string $errorCode = null): void
    {
        try {
            app(DirectMdfeDocumentWorkflow::class)->transition($document, CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION);
            $document->forceFill([
                'error_stage' => 'transmission',
                'error_code' => $errorCode,
                'error_message' => $message,
            ])->save();
        } catch (DomainException $exception) {
            Log::channel((string) config('dfe.log_channel'))->warning('Falha ao marcar MDF-e direto como failed_before_authorization.', [
                'mdfe_id' => $document->public_id,
                'status' => $document->status?->value,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}

<?php

namespace App\Jobs;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\MdfeDocument;
use App\Services\Cte\DirectMdfeDocumentWorkflow;
use App\Services\Cte\EmitMdfeDocument;
use App\Services\Dfe\ReserveMdfeDocuments;
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
        if ($document->fiscal_status === FiscalDocumentStatusEnum::AUTHORIZED) {
            if (blank($document->xml_path)) {
                $document->forceFill([
                    'error_stage' => 'artifact',
                    'error_code' => 'archive_pending',
                    'error_message' => $message,
                ])->save();
            }

            return;
        }

        $hasTransmissionArtifacts = filled($document->access_key) && filled($document->payload_xml);
        $targetStatus = $hasTransmissionArtifacts
            ? CteDocumentStatusEnum::RECONCILIATION_REQUIRED
            : CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION;

        try {
            app(DirectMdfeDocumentWorkflow::class)->transition($document, $targetStatus);
            $document->forceFill([
                'error_stage' => $hasTransmissionArtifacts ? 'transmission' : 'pre_transmission',
                'error_code' => $hasTransmissionArtifacts ? 'transmission_outcome_unknown' : $errorCode,
                'error_message' => $message,
            ])->save();

            if ($targetStatus === CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION) {
                app(ReserveMdfeDocuments::class)->releaseIfTerminal($document);
            }
        } catch (DomainException $exception) {
            Log::channel((string) config('dfe.log_channel'))->warning('Falha ao marcar MDF-e direto como failed_before_authorization.', [
                'mdfe_id' => $document->public_id,
                'status' => $document->status?->value,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}

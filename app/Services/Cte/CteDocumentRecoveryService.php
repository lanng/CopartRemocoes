<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Jobs\EmitCteDirectJob;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CteDocumentRecoveryService
{
    public static function canBeRequeued(CteDocument $document): bool
    {
        if ($document->emitter !== 'direct' || $document->fiscal_status === FiscalDocumentStatusEnum::AUTHORIZED) {
            return false;
        }

        if (in_array($document->status, [
            CteDocumentStatusEnum::AUTHORIZED,
            CteDocumentStatusEnum::AUTHORIZING,
            CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
            CteDocumentStatusEnum::CANCELLED,
            CteDocumentStatusEnum::SUPERSEDED,
        ], true)) {
            return false;
        }

        if ($document->status === CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION
            && (filled($document->access_key) || filled($document->payload_xml))) {
            return false;
        }

        return true;
    }

    public function retry(CteDocument $document): CteDocument
    {
        return DB::transaction(function () use ($document): CteDocument {
            $document = CteDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (! self::canBeRequeued($document)) {
                throw ValidationException::withMessages([
                    'document' => 'Este documento não pode ser reenfileirado com segurança; reconcilie o resultado fiscal ou aguarde a tentativa atual.',
                ]);
            }

            $this->resetForRetry($document);
            $this->reopenBatch($document);
            $this->dispatchDirectEmissionAfterCommit($document);

            return $document->refresh();
        }, attempts: 3);
    }

    /** @param Collection<int, CteDocument> $documents */
    public function retryMany(Collection $documents): int
    {
        return DB::transaction(function () use ($documents): int {
            $retried = 0;
            $batchIds = [];

            foreach ($documents as $document) {
                $document = CteDocument::query()->lockForUpdate()->findOrFail($document->id);

                if (! self::canBeRequeued($document)) {
                    continue;
                }

                $this->resetForRetry($document);
                $this->dispatchDirectEmissionAfterCommit($document);

                if ($document->cte_emission_batch_id !== null) {
                    $batchIds[$document->cte_emission_batch_id] = true;
                }

                $retried++;
            }

            if ($batchIds !== []) {
                CteEmissionBatch::query()
                    ->whereIn('id', array_keys($batchIds))
                    ->whereIn('status', [
                        CteEmissionBatchStatusEnum::COMPLETED,
                        CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
                    ])
                    ->update([
                        'status' => CteEmissionBatchStatusEnum::PROCESSING->value,
                        'completed_at' => null,
                    ]);
            }

            return $retried;
        });
    }

    private function resetForRetry(CteDocument $document): void
    {
        $document->forceFill([
            'status' => CteDocumentStatusEnum::QUEUED,
            'authorization_started_at' => null,
            'issued_at' => null,
            'authorized_at' => null,
            'cte_number' => null,
            'access_key' => null,
            'series' => null,
            'protocol' => null,
            'fiscal_status_code' => null,
            'fiscal_status_message' => null,
            'fiscal_status' => null,
            'payload_xml' => null,
            'response_xml' => null,
            'digest_value' => null,
            'xml_path' => null,
            'pdf_path' => null,
            'sha256' => null,
            'error_stage' => null,
            'error_code' => null,
            'error_message' => null,
            'result_payload_hash' => null,
        ])->save();
    }

    private function reopenBatch(CteDocument $document): void
    {
        if ($document->cte_emission_batch_id === null) {
            return;
        }

        CteEmissionBatch::query()
            ->whereKey($document->cte_emission_batch_id)
            ->whereIn('status', [
                CteEmissionBatchStatusEnum::COMPLETED,
                CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
            ])
            ->update([
                'status' => CteEmissionBatchStatusEnum::PROCESSING->value,
                'completed_at' => null,
            ]);
    }

    private function dispatchDirectEmissionAfterCommit(CteDocument $document): void
    {
        if ($document->emitter === 'direct' && $document->execution_mode === 'live') {
            EmitCteDirectJob::dispatch($document->id)->afterCommit();
        }
    }

    /** @param array{number: string, access_key: string, protocol: string, reason: string} $data */
    public function reconcile(CteDocument $document, array $data): CteDocument
    {
        if ($document->emitter !== 'direct') {
            throw ValidationException::withMessages([
                'document' => 'Documentos do emissor antigo estão disponíveis somente para consulta.',
            ]);
        }

        if (! preg_match('/^\d{44}$/', $data['access_key'])) {
            throw ValidationException::withMessages(['access_key' => 'A chave deve conter 44 digitos.']);
        }

        return DB::transaction(function () use ($document, $data): CteDocument {
            $document = CteDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($document->emitter !== 'direct') {
                throw ValidationException::withMessages([
                    'document' => 'Documentos do emissor antigo estão disponíveis somente para consulta.',
                ]);
            }

            if ($document->status !== CteDocumentStatusEnum::RECONCILIATION_REQUIRED) {
                throw ValidationException::withMessages([
                    'document' => 'Somente documentos em conciliacao podem ser regularizados.',
                ]);
            }

            $firstFiscalAuthorization = $document->fiscal_status !== FiscalDocumentStatusEnum::AUTHORIZED;

            $document->forceFill([
                'status' => CteDocumentStatusEnum::AUTHORIZED,
                'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
                'cte_number' => $data['number'],
                'access_key' => $data['access_key'],
                'protocol' => $data['protocol'],
                'fiscal_status_code' => '100',
                'fiscal_status_message' => 'Conciliado manualmente: '.$data['reason'],
                'authorized_at' => now(),
                'result_payload_hash' => hash('sha256', Str::uuid()->toString()),
            ])->save();

            if ($firstFiscalAuthorization) {
                app(MarkRegisterInvoicedForCte::class)->handle($document);
            }

            return $document->refresh();
        });
    }
}

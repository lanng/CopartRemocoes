<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CteDocumentRecoveryService
{
    /**
     * Statuses anteriores a barreira fiscal: o documento nunca chegou a ser
     * autorizado, logo pode voltar para a fila sem risco de CT-e duplicado.
     *
     * @var list<CteDocumentStatusEnum>
     */
    private const REQUEUEABLE_STATUSES = [
        CteDocumentStatusEnum::CLAIMED,
        CteDocumentStatusEnum::FILLING,
        CteDocumentStatusEnum::VALIDATING,
        CteDocumentStatusEnum::READY_TO_AUTHORIZE,
        CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        CteDocumentStatusEnum::REJECTED,
    ];

    /** @return list<CteDocumentStatusEnum> */
    public static function requeueableStatuses(): array
    {
        return self::REQUEUEABLE_STATUSES;
    }

    public function retry(CteDocument $document): CteDocument
    {
        if (! in_array($document->status, self::REQUEUEABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'document' => 'Somente documentos nao autorizados podem ser reenfileirados. Documentos apos a barreira fiscal exigem conciliacao.',
            ]);
        }

        $this->resetForRetry($document);

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

        return $document->refresh();
    }

    /** @param Collection<int, CteDocument> $documents */
    public function retryMany(Collection $documents): int
    {
        return DB::transaction(function () use ($documents): int {
            $retried = 0;
            $batchIds = [];

            foreach ($documents as $document) {
                $document = CteDocument::query()->lockForUpdate()->findOrFail($document->id);

                if (! in_array($document->status, self::REQUEUEABLE_STATUSES, true)) {
                    continue;
                }

                $this->resetForRetry($document);
                $batchIds[$document->cte_emission_batch_id] = true;
                $retried++;
            }

            if ($retried > 0) {
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
            'claimed_by' => null,
            'claim_token_hash' => null,
            'claimed_at' => null,
            'claim_expires_at' => null,
            'error_stage' => null,
            'error_code' => null,
            'error_message' => null,
            'result_payload_hash' => null,
        ])->save();
    }

    /** @param array{number: string, access_key: string, protocol: string, reason: string} $data */
    public function reconcile(CteDocument $document, array $data): CteDocument
    {
        if ($document->status !== CteDocumentStatusEnum::RECONCILIATION_REQUIRED) {
            throw ValidationException::withMessages([
                'document' => 'Somente documentos em conciliacao podem ser regularizados.',
            ]);
        }

        if (! preg_match('/^\d{44}$/', $data['access_key'])) {
            throw ValidationException::withMessages(['access_key' => 'A chave deve conter 44 digitos.']);
        }

        return DB::transaction(function () use ($document, $data): CteDocument {
            $document->forceFill([
                'status' => CteDocumentStatusEnum::AUTHORIZED,
                'cte_number' => $data['number'],
                'access_key' => $data['access_key'],
                'protocol' => $data['protocol'],
                'fiscal_status_code' => '100',
                'fiscal_status_message' => 'Conciliado manualmente: '.$data['reason'],
                'authorized_at' => now(),
                'result_payload_hash' => hash('sha256', Str::uuid()->toString()),
            ])->save();

            return $document->refresh();
        });
    }
}

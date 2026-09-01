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
     * Unico status de onde o documento nao volta para a fila: o CT-e ja foi
     * autorizado, e reemitir geraria um documento duplicado.
     */
    public static function canBeRequeued(CteDocumentStatusEnum $status): bool
    {
        return $status !== CteDocumentStatusEnum::AUTHORIZED;
    }

    public function retry(CteDocument $document): CteDocument
    {
        if (! self::canBeRequeued($document->status)) {
            throw ValidationException::withMessages([
                'document' => 'Documentos autorizados nao podem ser reenfileirados.',
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

                if (! self::canBeRequeued($document->status)) {
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
            'authorization_started_at' => null,
            'issued_at' => null,
            'authorized_at' => null,
            'cte_number' => null,
            'access_key' => null,
            'series' => null,
            'protocol' => null,
            'fiscal_status_code' => null,
            'fiscal_status_message' => null,
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

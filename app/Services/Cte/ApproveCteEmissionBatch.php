<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveCteEmissionBatch
{
    public function handle(CteEmissionBatch $batch, User $user): CteEmissionBatch
    {
        return DB::transaction(function () use ($batch, $user): CteEmissionBatch {
            $batch = CteEmissionBatch::query()->with('documents.register')->lockForUpdate()->findOrFail($batch->id);

            if ($batch->status === CteEmissionBatchStatusEnum::APPROVED) {
                return $batch;
            }

            if ($batch->status !== CteEmissionBatchStatusEnum::DRAFT) {
                throw ValidationException::withMessages(['batch' => 'Somente lotes em rascunho podem ser aprovados.']);
            }

            foreach ($batch->documents as $document) {
                $this->ensureSnapshotStillMatches($document->register, $document->snapshot);
            }

            $batch->forceFill([
                'status' => CteEmissionBatchStatusEnum::APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            $batch->documents()->update(['status' => CteDocumentStatusEnum::QUEUED]);

            return $batch->refresh()->load('documents');
        });
    }

    /** @param array<string, mixed> $snapshot */
    private function ensureSnapshotStillMatches(Register $register, array $snapshot): void
    {
        $fields = [
            'vehicle_id', 'vehicle_model', 'vehicle_plate', 'origin_city',
            'destination_city', 'payment_code', 'insurance', 'fipe_value', 'value',
        ];

        foreach ($fields as $field) {
            $current = $register->{$field};
            $current = $current === null ? null : (string) $current;

            if ($current !== ($snapshot[$field] ?? null)) {
                throw ValidationException::withMessages([
                    'batch' => "A remocao {$register->id} foi alterada depois da criacao do lote. Crie um novo lote.",
                ]);
            }
        }
    }
}

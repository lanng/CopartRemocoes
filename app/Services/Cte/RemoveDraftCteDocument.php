<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveDraftCteDocument
{
    public function handle(CteDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $batch = CteEmissionBatch::query()
                ->lockForUpdate()
                ->findOrFail($document->cte_emission_batch_id);
            $lockedDocument = CteDocument::query()
                ->lockForUpdate()
                ->findOrFail($document->id);

            if ($lockedDocument->cte_emission_batch_id !== $batch->id) {
                throw ValidationException::withMessages([
                    'document' => 'O documento não pertence ao lote informado.',
                ]);
            }

            if ($batch->status !== CteEmissionBatchStatusEnum::DRAFT) {
                throw ValidationException::withMessages([
                    'batch' => 'Somente lotes em rascunho podem ter documentos retirados.',
                ]);
            }

            if ($lockedDocument->status !== CteDocumentStatusEnum::DRAFT) {
                throw ValidationException::withMessages([
                    'document' => 'Somente documentos em rascunho podem ser retirados.',
                ]);
            }

            $lockedDocument->delete();

            if (! $batch->documents()->exists()) {
                $batch->delete();
            }
        });
    }
}

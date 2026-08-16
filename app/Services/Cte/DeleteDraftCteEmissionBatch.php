<?php

namespace App\Services\Cte;

use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteEmissionBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteDraftCteEmissionBatch
{
    public function handle(CteEmissionBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $batch = CteEmissionBatch::query()
                ->lockForUpdate()
                ->findOrFail($batch->id);

            if ($batch->status !== CteEmissionBatchStatusEnum::DRAFT) {
                throw ValidationException::withMessages([
                    'batch' => 'Somente lotes em rascunho podem ser excluídos.',
                ]);
            }

            $batch->delete();
        });
    }
}

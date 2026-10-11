<?php

namespace App\Services\Cte;

use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteEmissionBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteDraftCteEmissionBatch
{
    public function __construct(private readonly RemoveDraftCteDocument $documentRemover) {}

    public function handle(CteEmissionBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $lockedBatch = CteEmissionBatch::query()
                ->lockForUpdate()
                ->findOrFail($batch->id);

            if ($lockedBatch->status !== CteEmissionBatchStatusEnum::DRAFT) {
                throw ValidationException::withMessages([
                    'batch' => 'Somente lotes em rascunho podem ser excluídos.',
                ]);
            }

            foreach ($lockedBatch->documents()->lockForUpdate()->get() as $document) {
                if ($this->documentRemover->canRemove($document)) {
                    $this->documentRemover->handle($document);
                }
            }

            if (CteEmissionBatch::query()->whereKey($lockedBatch->id)->exists()) {
                $lockedBatch->delete();
            }
        });
    }

    public function canDelete(CteEmissionBatch $batch): bool
    {
        $batch = CteEmissionBatch::query()->find($batch->id);

        return $batch !== null && $batch->status === CteEmissionBatchStatusEnum::DRAFT;
    }
}

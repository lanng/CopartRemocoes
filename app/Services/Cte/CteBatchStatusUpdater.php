<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteEmissionBatch;

class CteBatchStatusUpdater
{
    public function handle(CteEmissionBatch $batch): CteEmissionBatch
    {
        $statuses = $batch->documents()->get(['status'])->pluck('status')->map(
            fn (CteDocumentStatusEnum|string $status): CteDocumentStatusEnum => $status instanceof CteDocumentStatusEnum
                ? $status
                : CteDocumentStatusEnum::from($status)
        );

        if ($statuses->isEmpty()) {
            return $batch;
        }

        $activeStatuses = [
            CteDocumentStatusEnum::DRAFT,
            CteDocumentStatusEnum::QUEUED,
            CteDocumentStatusEnum::CLAIMED,
            CteDocumentStatusEnum::FILLING,
            CteDocumentStatusEnum::VALIDATING,
            CteDocumentStatusEnum::READY_TO_AUTHORIZE,
            CteDocumentStatusEnum::AUTHORIZING,
            CteDocumentStatusEnum::WAITING_FOR_XML,
        ];

        if ($statuses->contains(fn (CteDocumentStatusEnum $status): bool => in_array($status, $activeStatuses, true))) {
            $target = $statuses->contains(CteDocumentStatusEnum::DRAFT)
                ? CteEmissionBatchStatusEnum::DRAFT
                : ($statuses->contains(CteDocumentStatusEnum::QUEUED) && $batch->status === CteEmissionBatchStatusEnum::APPROVED
                    ? CteEmissionBatchStatusEnum::APPROVED
                    : CteEmissionBatchStatusEnum::PROCESSING);

            $batch->forceFill(['status' => $target])->save();

            return $batch->refresh();
        }

        $hasErrors = $statuses->contains(fn (CteDocumentStatusEnum $status): bool => in_array($status, [
            CteDocumentStatusEnum::REJECTED,
            CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
            CteDocumentStatusEnum::CANCELLED,
        ], true));

        $batch->forceFill([
            'status' => $hasErrors ? CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS : CteEmissionBatchStatusEnum::COMPLETED,
            'completed_at' => now(),
        ])->save();

        return $batch->refresh();
    }
}

<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteDocument;
use DomainException;

/**
 * Coreografia do emissor direto: draft → queued → authorizing → authorized|rejected.
 * Falhas locais sem artifacts
 * vão para failed_before_authorization; resultado de transmissão incerto ou
 * divergência de digVal exige reconciliation_required.
 */
class DirectCteDocumentWorkflow
{
    /**
     * @var array<string, list<CteDocumentStatusEnum>>
     */
    private const TRANSITIONS = [
        'draft' => [CteDocumentStatusEnum::QUEUED],
        'queued' => [
            CteDocumentStatusEnum::AUTHORIZING,
            CteDocumentStatusEnum::DRY_RUN_COMPLETED,
            CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ],
        'authorizing' => [
            CteDocumentStatusEnum::AUTHORIZED,
            CteDocumentStatusEnum::REJECTED,
            CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
            CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        ],
        'failed_before_authorization' => [],
        'dry_run_completed' => [],
        'authorized' => [CteDocumentStatusEnum::SUPERSEDED, CteDocumentStatusEnum::CANCELLED],
        'rejected' => [],
        'reconciliation_required' => [],
        'cancelled' => [],
        'superseded' => [],
    ];

    public function transition(CteDocument $document, CteDocumentStatusEnum $target): void
    {
        $current = $document->status;

        if (! $current instanceof CteDocumentStatusEnum) {
            throw new DomainException('The document does not have a valid status.');
        }

        $allowed = self::TRANSITIONS[$current->value] ?? [];

        if (! in_array($target, $allowed, true)) {
            throw new DomainException("Cannot transition a {$current->value} document to {$target->value}.");
        }

        $document->status = $target;

        if ($document->exists) {
            $document->save();
        }
    }
}

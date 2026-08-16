<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteDocument;
use DomainException;

class CteDocumentWorkflow
{
    /**
     * @var array<string, list<CteDocumentStatusEnum>>
     */
    private const TRANSITIONS = [
        'draft' => [CteDocumentStatusEnum::QUEUED],
        'queued' => [CteDocumentStatusEnum::CLAIMED],
        'claimed' => [CteDocumentStatusEnum::FILLING, CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION],
        'filling' => [CteDocumentStatusEnum::VALIDATING, CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION],
        'validating' => [CteDocumentStatusEnum::READY_TO_AUTHORIZE, CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION, CteDocumentStatusEnum::DRY_RUN_COMPLETED],
        'ready_to_authorize' => [CteDocumentStatusEnum::AUTHORIZING, CteDocumentStatusEnum::DRY_RUN_COMPLETED, CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION],
        'authorizing' => [CteDocumentStatusEnum::WAITING_FOR_XML, CteDocumentStatusEnum::RECONCILIATION_REQUIRED],
        'waiting_for_xml' => [CteDocumentStatusEnum::AUTHORIZED, CteDocumentStatusEnum::REJECTED, CteDocumentStatusEnum::RECONCILIATION_REQUIRED],
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

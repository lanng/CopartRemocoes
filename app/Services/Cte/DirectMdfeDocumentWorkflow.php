<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\MdfeDocument;
use DomainException;

class DirectMdfeDocumentWorkflow
{
    /** @var array<string, list<CteDocumentStatusEnum>> */
    private const TRANSITIONS = [
        'queued' => [CteDocumentStatusEnum::AUTHORIZING, CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION, CteDocumentStatusEnum::RECONCILIATION_REQUIRED],
        'authorizing' => [CteDocumentStatusEnum::AUTHORIZED, CteDocumentStatusEnum::REJECTED, CteDocumentStatusEnum::RECONCILIATION_REQUIRED, CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION],
        'authorized' => [CteDocumentStatusEnum::CANCELLED],
        'failed_before_authorization' => [],
        'rejected' => [],
        'reconciliation_required' => [],
        'cancelled' => [],
    ];

    public function transition(MdfeDocument $document, CteDocumentStatusEnum $target): void
    {
        $current = $document->status;

        if (! $current instanceof CteDocumentStatusEnum || ! in_array($target, self::TRANSITIONS[$current->value] ?? [], true)) {
            throw new DomainException(sprintf(
                'Não é permitido mudar o MDF-e de %s para %s.',
                $current instanceof CteDocumentStatusEnum ? $current->value : 'status inválido',
                $target->value,
            ));
        }

        $document->status = $target;

        if ($document->exists) {
            $document->save();
        }
    }
}

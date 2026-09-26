<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\MdfeDocument;
use DomainException;

class MdfeDocumentWorkflow
{
    /**
     * Estágios ativos do pipeline, em ordem. O progresso do MDF-e pode saltar
     * estágios intermediários — a coreografia do Lab difere do CT-e (ex.: não
     * existe um "validating" distinto) — sempre para frente, nunca para trás.
     *
     * @var list<CteDocumentStatusEnum>
     */
    private const PIPELINE = [
        CteDocumentStatusEnum::CLAIMED,
        CteDocumentStatusEnum::FILLING,
        CteDocumentStatusEnum::VALIDATING,
        CteDocumentStatusEnum::READY_TO_AUTHORIZE,
        CteDocumentStatusEnum::AUTHORIZING,
        CteDocumentStatusEnum::WAITING_FOR_XML,
    ];

    /**
     * Desfechos específicos por estágio; o restante do pipeline à frente é
     * acrescentado em allowedTargets.
     *
     * @var array<string, list<CteDocumentStatusEnum>>
     */
    private const TRANSITIONS = [
        'draft' => [CteDocumentStatusEnum::QUEUED],
        'queued' => [CteDocumentStatusEnum::CLAIMED],
        'claimed' => [CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION],
        'filling' => [CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION],
        'validating' => [CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION, CteDocumentStatusEnum::DRY_RUN_COMPLETED],
        'ready_to_authorize' => [CteDocumentStatusEnum::DRY_RUN_COMPLETED, CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION],
        'authorizing' => [CteDocumentStatusEnum::RECONCILIATION_REQUIRED],
        'waiting_for_xml' => [CteDocumentStatusEnum::AUTHORIZED, CteDocumentStatusEnum::REJECTED, CteDocumentStatusEnum::RECONCILIATION_REQUIRED],
        'failed_before_authorization' => [],
        'dry_run_completed' => [],
        'authorized' => [CteDocumentStatusEnum::SUPERSEDED, CteDocumentStatusEnum::CANCELLED],
        'rejected' => [],
        'reconciliation_required' => [],
        'cancelled' => [],
        'superseded' => [],
    ];

    public function transition(MdfeDocument $document, CteDocumentStatusEnum $target): void
    {
        $current = $document->status;

        if (! $current instanceof CteDocumentStatusEnum) {
            throw new DomainException('The document does not have a valid status.');
        }

        if (! in_array($target, $this->allowedTargets($current), true)) {
            throw new DomainException("Cannot transition a {$current->value} document to {$target->value}.");
        }

        $document->status = $target;

        if ($document->exists) {
            $document->save();
        }
    }

    /**
     * Alvos do estágio: os desfechos do mapa + todos os estágios do pipeline
     * à sua frente (salto permitido; retorno, nunca). A barreira fiscal segue
     * valendo: `authorized` só a partir de `waiting_for_xml`.
     *
     * @return list<CteDocumentStatusEnum>
     */
    protected function allowedTargets(CteDocumentStatusEnum $current): array
    {
        $allowed = self::TRANSITIONS[$current->value] ?? [];

        $position = array_search($current, self::PIPELINE, true);

        if ($position !== false) {
            $allowed = [...$allowed, ...array_slice(self::PIPELINE, $position + 1)];
        }

        return $allowed;
    }
}

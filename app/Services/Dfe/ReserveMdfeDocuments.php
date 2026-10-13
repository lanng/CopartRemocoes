<?php

namespace App\Services\Dfe;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReserveMdfeDocuments
{
    /** @param Collection<int, CteDocument> $documents */
    public function reserve(MdfeDocument $mdfe, Collection $documents, Ciot $ciot): void
    {
        $documentIds = $documents->map(fn (CteDocument $document): int => (int) $document->id)->sort()->values();

        if (! $mdfe->exists || $documentIds->isEmpty() || $documentIds->contains(0)) {
            throw new DomainException('O MDF-e e os CT-es persistidos são obrigatórios para reservar a seleção.');
        }

        if ($documentIds->unique()->count() !== $documentIds->count()) {
            throw new DomainException('A seleção contém CT-es duplicados e não pode ser reservada.');
        }

        if ((int) $mdfe->ciot_id !== (int) $ciot->id) {
            throw new DomainException('O CIOT reservado deve ser o mesmo vinculado ao MDF-e.');
        }

        DB::transaction(function () use ($mdfe, $documentIds, $ciot): void {
            $lockedDocuments = CteDocument::query()
                ->whereIn('id', $documentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedDocuments->count() !== $documentIds->count()) {
                throw new DomainException('Um ou mais CT-es selecionados não existem mais. Atualize a seleção.');
            }

            if ($lockedDocuments->contains(fn (CteDocument $document): bool => $document->status !== CteDocumentStatusEnum::AUTHORIZED
                || $document->workflow_origin !== FiscalDocumentWorkflowOriginEnum::ControlledV1
                || ! preg_match('/^\d{44}$/', (string) $document->access_key))) {
                throw new DomainException('Somente CT-es autorizados do fluxo controlado podem ser reservados.');
            }

            $this->assertCtesHaveNoOtherNonCancelledMdfe($mdfe, $documentIds);

            $lockedCiot = Ciot::query()->whereKey($ciot->id)->lockForUpdate()->firstOrFail();

            if ($lockedCiot->isSyntheticHomologation()
                || in_array($mdfe->snapshot['ciot_source'] ?? null, ['synthetic_homologation', 'homologation_fixture'], true)) {
                throw new DomainException('CIOT sintético de homologação não pode ser reservado para emissão de MDF-e.');
            }

            if (! $lockedCiot->isOpen()) {
                throw new DomainException('Somente um CIOT emitido e aberto pode ser reservado para o MDF-e.');
            }

            $this->assertMdfeSelectionMatches($mdfe, $documentIds);
            $this->assertCiotHasNoOtherActiveMdfe($mdfe, $lockedCiot);

            $resources = $documentIds
                ->map(fn (int $id): array => ['resource_type' => 'cte', 'resource_id' => $id])
                ->push(['resource_type' => 'ciot', 'resource_id' => (int) $lockedCiot->id]);

            foreach ($resources as $resource) {
                $reservation = DfeDocumentReservation::query()
                    ->where('resource_type', $resource['resource_type'])
                    ->where('resource_id', $resource['resource_id'])
                    ->lockForUpdate()
                    ->first();

                if ($reservation !== null) {
                    if ((int) $reservation->mdfe_document_id !== (int) $mdfe->id) {
                        throw new DomainException(sprintf(
                            'O recurso %s #%d já está reservado por outro MDF-e ativo.',
                            $resource['resource_type'],
                            $resource['resource_id'],
                        ));
                    }

                    continue;
                }

                DfeDocumentReservation::query()->create([
                    'resource_type' => $resource['resource_type'],
                    'resource_id' => $resource['resource_id'],
                    'mdfe_document_id' => $mdfe->id,
                ]);
            }
        }, attempts: 3);
    }

    public function releaseIfTerminal(MdfeDocument $mdfe): int
    {
        return DB::transaction(function () use ($mdfe): int {
            $lockedMdfe = MdfeDocument::query()->whereKey($mdfe->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedMdfe->fiscal_status, [FiscalDocumentStatusEnum::CANCELLED, FiscalDocumentStatusEnum::CLOSED], true)) {
                return DfeDocumentReservation::query()
                    ->where('mdfe_document_id', $lockedMdfe->id)
                    ->where('resource_type', 'cte')
                    ->delete();
            }

            if ($lockedMdfe->fiscal_status === FiscalDocumentStatusEnum::AUTHORIZED
                || ! in_array($lockedMdfe->status, [
                    CteDocumentStatusEnum::REJECTED,
                    CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
                ], true)) {
                return 0;
            }

            return DfeDocumentReservation::query()->where('mdfe_document_id', $lockedMdfe->id)->delete();
        }, attempts: 3);
    }

    public function releaseCiotIfTerminal(Ciot $ciot): int
    {
        return DB::transaction(function () use ($ciot): int {
            $lockedCiot = Ciot::query()->whereKey($ciot->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedCiot->status, [CiotStatusEnum::CANCELED, CiotStatusEnum::CLOSED], true)) {
                return 0;
            }

            return DfeDocumentReservation::query()
                ->where('resource_type', 'ciot')
                ->where('resource_id', $lockedCiot->id)
                ->delete();
        }, attempts: 3);
    }

    /** @param Collection<int, int> $documentIds */
    private function assertMdfeSelectionMatches(MdfeDocument $mdfe, Collection $documentIds): void
    {
        $linkedIds = $mdfe->cteDocuments()
            ->orderBy('cte_documents.id')
            ->pluck('cte_documents.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if ($linkedIds->all() !== $documentIds->all()) {
            throw new DomainException('Os vínculos do MDF-e devem corresponder exatamente aos CT-es que serão reservados.');
        }
    }

    private function assertCiotHasNoOtherActiveMdfe(MdfeDocument $mdfe, Ciot $ciot): void
    {
        $activeMdfeExists = $ciot->mdfeDocuments()
            ->where('mdfe_documents.id', '!=', $mdfe->id)
            ->whereNotIn('mdfe_documents.status', [
                CteDocumentStatusEnum::REJECTED->value,
                CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION->value,
                CteDocumentStatusEnum::CANCELLED->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('mdfe_documents.fiscal_status')
                    ->orWhereNotIn('mdfe_documents.fiscal_status', [
                        FiscalDocumentStatusEnum::CANCELLED->value,
                        FiscalDocumentStatusEnum::CLOSED->value,
                    ]);
            })
            ->exists();

        if ($activeMdfeExists) {
            throw new DomainException('Este CIOT já está vinculado a outro MDF-e ativo ou em processamento.');
        }
    }

    /** @param Collection<int, int> $documentIds */
    private function assertCtesHaveNoOtherNonCancelledMdfe(MdfeDocument $mdfe, Collection $documentIds): void
    {
        $linkedToOtherNonCancelledMdfe = MdfeDocument::query()
            ->whereKeyNot($mdfe->id)
            ->whereHas('cteDocuments', fn (Builder $query): Builder => $query->whereIn('cte_documents.id', $documentIds))
            ->where('status', '!=', CteDocumentStatusEnum::CANCELLED->value)
            ->where(function (Builder $query): void {
                $query->whereNull('fiscal_status')
                    ->orWhere('fiscal_status', '!=', FiscalDocumentStatusEnum::CANCELLED->value);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('fiscal_status_code')
                    ->orWhere('fiscal_status_code', '!=', '101');
            })
            ->exists();

        if ($linkedToOtherNonCancelledMdfe) {
            throw new DomainException('Um ou mais CT-es já estão vinculados a outro MDF-e não cancelado.');
        }
    }
}

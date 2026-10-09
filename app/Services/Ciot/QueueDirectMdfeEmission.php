<?php

namespace App\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Jobs\EmitMdfeDirectJob;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use App\Services\Dfe\ReserveMdfeDocuments;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueDirectMdfeEmission
{
    public function __construct(
        private readonly BuildMdfeEmissionSnapshot $snapshotBuilder,
        private readonly ValidateMdfeDocumentSelection $selectionValidator,
        private readonly ReserveMdfeDocuments $reservationService,
    ) {}

    /**
     * @param  array<int, string>  $endorsements
     * @param  array<int, string>  $routeUfs
     * @param  array{insurer_name: string, insurer_cnpj: string, policy_number: string}|null  $homologationFixture
     */
    public function handle(
        CteEmissionBatch $batch,
        array $endorsements,
        array $routeUfs,
        ?MdfeDriver $driver = null,
        ?array $homologationFixture = null,
        ?string $homologationCiotNumber = null,
        ?string $paymentMethod = null,
        ?string $paymentBankCode = null,
        ?string $paymentBankAgency = null,
    ): MdfeDocument {
        $ciot = $batch->ciots()
            ->where('status', CiotStatusEnum::ISSUED->value)
            ->latest('id')
            ->first();

        if (! $ciot instanceof Ciot && $homologationCiotNumber !== null) {
            $ciot = $batch->ciots()
                ->where('status', CiotStatusEnum::FAILED->value)
                ->where('error_code', '217')
                ->whereNotNull('payload')
                ->latest('id')
                ->first();
        }

        if (! $ciot instanceof Ciot) {
            throw new DomainException('CIOT emitido não encontrado para este lote.');
        }

        return $this->handleForDocuments(
            documents: $batch->documents()->get(),
            ciot: $ciot,
            endorsements: $endorsements,
            routeUfs: $routeUfs,
            batch: $batch,
            driver: $driver,
            homologationFixture: $homologationFixture,
            homologationCiotNumber: $homologationCiotNumber,
            paymentMethod: $paymentMethod,
            paymentBankCode: $paymentBankCode,
            paymentBankAgency: $paymentBankAgency,
        );
    }

    /**
     * @param  Collection<int, CteDocument>  $documents
     * @param  array<int, string>  $endorsements
     * @param  array<int, string>  $routeUfs
     * @param  array{insurer_name: string, insurer_cnpj: string, policy_number: string}|null  $homologationFixture
     */
    public function handleForDocuments(
        Collection $documents,
        Ciot $ciot,
        array $endorsements,
        array $routeUfs,
        ?CteEmissionBatch $batch = null,
        ?MdfeDriver $driver = null,
        ?array $homologationFixture = null,
        ?string $homologationCiotNumber = null,
        ?string $paymentMethod = null,
        ?string $paymentBankCode = null,
        ?string $paymentBankAgency = null,
    ): MdfeDocument {
        return DB::transaction(function () use (
            $documents,
            $ciot,
            $endorsements,
            $routeUfs,
            $batch,
            $driver,
            $homologationFixture,
            $homologationCiotNumber,
            $paymentMethod,
            $paymentBankCode,
            $paymentBankAgency,
        ): MdfeDocument {
            $lockedBatch = $batch === null
                ? null
                : CteEmissionBatch::query()->findOrFail($batch->id);

            $this->selectionValidator->handle($documents, $ciot, $homologationCiotNumber);
            $snapshot = $this->snapshotBuilder->handleForDocuments(
                documents: $documents,
                ciot: $ciot,
                endorsements: $endorsements,
                routeUfs: $routeUfs,
                batch: $lockedBatch,
                driver: $driver,
                homologationFixture: $homologationFixture,
                homologationCiotNumber: $homologationCiotNumber,
                paymentMethod: $paymentMethod,
                paymentBankCode: $paymentBankCode,
                paymentBankAgency: $paymentBankAgency,
            );
            $mdfe = MdfeDocument::query()->create([
                'public_id' => (string) Str::uuid(),
                'cte_emission_batch_id' => $lockedBatch?->id,
                'ciot_id' => $ciot->id,
                'status' => CteDocumentStatusEnum::QUEUED,
                'snapshot' => $snapshot,
                'idempotency_key' => (string) Str::uuid(),
                'execution_mode' => 'live',
                'emitter' => 'direct',
                'workflow_origin' => FiscalDocumentWorkflowOriginEnum::ControlledV1,
            ]);

            $mdfe->cteDocuments()->attach($documents
                ->values()
                ->mapWithKeys(fn (CteDocument $document, int $position): array => [
                    $document->id => ['position' => $position + 1],
                ])
                ->all());

            $this->reservationService->reserve($mdfe, $documents, $ciot);

            EmitMdfeDirectJob::dispatch($mdfe->id)->afterCommit();

            return $mdfe;
        });
    }
}

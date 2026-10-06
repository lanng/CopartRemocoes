<?php

namespace App\Services\Ciot;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueDirectMdfeEmission
{
    public function __construct(private readonly BuildMdfeEmissionSnapshot $snapshotBuilder) {}

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
        return DB::transaction(function () use ($batch, $endorsements, $routeUfs, $driver, $homologationFixture, $homologationCiotNumber, $paymentMethod, $paymentBankCode, $paymentBankAgency): MdfeDocument {
            $batch = CteEmissionBatch::query()->lockForUpdate()->findOrFail($batch->id);

            $hasIncompleteCte = $batch->documents()
                ->where('status', '!=', CteDocumentStatusEnum::AUTHORIZED->value)
                ->exists();

            if ($hasIncompleteCte) {
                throw new DomainException('Todos os CT-es da viagem precisam estar autorizados antes de montar o MDF-e direto.');
            }

            $activeMdfeExists = $batch->mdfeDocuments()
                ->whereNotIn('status', [
                    CteDocumentStatusEnum::REJECTED->value,
                    CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION->value,
                    CteDocumentStatusEnum::CANCELLED->value,
                ])
                ->exists();

            if ($activeMdfeExists) {
                throw new DomainException('Esta viagem já possui um MDF-e ativo ou em processamento.');
            }

            $snapshot = $this->snapshotBuilder->handle(
                batch: $batch,
                endorsements: $endorsements,
                routeUfs: $routeUfs,
                driver: $driver,
                homologationFixture: $homologationFixture,
                homologationCiotNumber: $homologationCiotNumber,
                paymentMethod: $paymentMethod,
                paymentBankCode: $paymentBankCode,
                paymentBankAgency: $paymentBankAgency,
            );

            return MdfeDocument::query()->create([
                'public_id' => (string) Str::uuid(),
                'cte_emission_batch_id' => $batch->id,
                'status' => CteDocumentStatusEnum::QUEUED,
                'snapshot' => $snapshot,
                'idempotency_key' => (string) Str::uuid(),
                'execution_mode' => 'live',
                'emitter' => 'direct',
            ]);
        });
    }
}

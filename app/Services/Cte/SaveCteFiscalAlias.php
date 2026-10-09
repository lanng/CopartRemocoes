<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\City;
use App\Models\Company;
use App\Models\CompanyFiscalAlias;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\User;
use DomainException;
use Illuminate\Validation\ValidationException;

class SaveCteFiscalAlias
{
    public function handle(CteEmissionBatch $batch, CteDocument $document, string $role, int $companyId, ?int $cityId, User $user): CompanyFiscalAlias
    {
        return $batch->getConnection()->transaction(function () use ($batch, $document, $role, $companyId, $cityId, $user): CompanyFiscalAlias {
            $lockedBatch = CteEmissionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $lockedDocument = $lockedBatch->documents()->lockForUpdate()->findOrFail($document->id);

            if ($lockedBatch->status !== CteEmissionBatchStatusEnum::DRAFT || $lockedDocument->status !== CteDocumentStatusEnum::DRAFT) {
                throw ValidationException::withMessages(['batch' => 'Somente lotes e documentos em rascunho podem ser conciliados.']);
            }

            if (! in_array($role, ['sender', 'destination'], true)) {
                throw ValidationException::withMessages(['role' => 'Tipo de associação inválido.']);
            }

            $company = Company::query()->where('is_active', true)->find($companyId);
            if ($company === null) {
                throw ValidationException::withMessages(['company_id' => 'Selecione uma empresa ativa.']);
            }

            try {
                app(ResolveCteSnapshot::class)->companyBlock($company);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['company_id' => $exception->getMessage()]);
            }

            if ($role === 'destination' && ($cityId === null || ! City::query()->whereKey($cityId)->exists())) {
                throw ValidationException::withMessages(['city_id' => 'Confirme o município efetivo do destino.']);
            }

            $sourceName = (string) ($lockedDocument->snapshot[$role === 'sender' ? 'insurance' : 'destination_city'] ?? '');
            if (blank(CompanyFiscalAlias::normalize($sourceName)) || strlen(CompanyFiscalAlias::normalize($sourceName)) > 255) {
                throw ValidationException::withMessages(['batch' => 'O nome original do registro está vazio ou inválido. Corrija o registro e prepare um novo lote.']);
            }

            $alias = CompanyFiscalAlias::query()->firstOrCreate([
                'role' => $role,
                'normalized_name' => CompanyFiscalAlias::normalize($sourceName),
            ], [
                'source_name' => $sourceName,
                'company_id' => $companyId,
                'city_id' => $role === 'destination' ? $cityId : null,
                'created_by' => $user->id,
            ]);
            $alias->fill([
                'source_name' => $sourceName,
                'company_id' => $companyId,
                'city_id' => $role === 'destination' ? $cityId : null,
            ])->save();

            return $alias;
        });
    }
}

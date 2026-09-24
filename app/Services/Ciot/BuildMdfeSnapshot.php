<?php

namespace App\Services\Ciot;

use App\Models\Ciot;
use App\Models\CteEmissionBatch;
use DomainException;
use Illuminate\Support\Str;

/**
 * Monta o snapshot MDF-e (docs/superpowers/specs/2026-09-23-mdfe-agent-payload.md)
 * a partir do lote de CT-e e do CIOT emitido da viagem.
 */
class BuildMdfeSnapshot
{
    /**
     * @return array<string, mixed>
     *
     * @throws DomainException listando todos os dados faltantes
     */
    public function handle(CteEmissionBatch $batch): array
    {
        $missing = [];

        $ciot = $batch->ciots()
            ->where('status', 'issued')
            ->latest('id')
            ->first();

        if ($ciot === null) {
            throw new DomainException('CIOT emitido não encontrado para este lote.');
        }

        $authorizedDocuments = $batch->documents()
            ->where('status', 'authorized')
            ->whereNotNull('access_key')
            ->get();

        $cteAccessKeys = $authorizedDocuments
            ->pluck('access_key')
            ->filter()
            ->values()
            ->all();

        if ($cteAccessKeys === []) {
            $missing[] = 'nenhum CT-e autorizado com chave de acesso neste lote.';
        }

        $originCep = preg_replace('/\D/', '', (string) ($ciot->origin['cep'] ?? ''));
        $destinationCep = preg_replace('/\D/', '', (string) ($ciot->destination['cep'] ?? ''))
            ?: preg_replace('/\D/', '', (string) $ciot->deliveryPayer?->zipcode);

        if (strlen((string) $originCep) !== 8) {
            $missing[] = 'CEP de origem (8 dígitos) não informado no CIOT.';
        }

        if (strlen((string) $destinationCep) !== 8) {
            $missing[] = 'CEP de destino não cadastrado no pagante destinatário.';
        }

        if ($missing !== []) {
            throw new DomainException('MDF-e não pode ser despachado: '.implode(' ', $missing));
        }

        $company = (string) ($batch->documents->first()?->snapshot['company'] ?? 'copart');

        return [
            'schema_version' => 1,
            'trip_id' => $batch->id,
            'company' => $company,
            'origin_city' => (string) ($ciot->origin['cidade'] ?? ''),
            'destination_city' => (string) ($ciot->destination['cidade'] ?? ''),
            'driver_code' => (string) config('mdfe.driver_code'),
            'vehicle_code' => (string) config('mdfe.vehicle_code'),
            'ciot' => (string) $ciot->ciot_number,
            'cargo_value' => $this->decimal($batch->totalCargoValueInCents() / 100),
            'cargo_weight_kg' => $this->decimal((float) $ciot->cargo_weight_kg),
            'origin_cep' => (string) $originCep,
            'destination_cep' => (string) $destinationCep,
            'payment_doc' => (string) $ciot->payer_cnpj,
            'payment_name' => (string) $ciot->payer_name,
            'payment_value' => $this->decimal($ciot->freight_value_cents / 100),
            'payment_bank' => (string) config('ciot.lines.vehicle_removal.bank_code'),
            'payment_agency' => str_pad((string) config('ciot.lines.vehicle_removal.bank_agency'), 4, '0', STR_PAD_LEFT),
            'cte_access_keys' => $cteAccessKeys,
        ];
    }

    protected function decimal(int|float $value): string
    {
        return Str::of(number_format((float) $value, 2, '.', ''))->toString();
    }
}

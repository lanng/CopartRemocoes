<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\MdfeDocument;
use DomainException;

/**
 * Conciliação manual de um MDF-e em `reconciliation_required`: o agente
 * emitiu e autorizou no Lab, mas não conseguiu reportar o resultado (ex.:
 * falha lendo o XML de saída). O operador informa a chave de acesso; número
 * e série são derivados das posições da própria chave (layout do MDF-e:
 * série nas posições 23–25, número nas 26–34).
 */
class ReconcileMdfeDocument
{
    public function handle(MdfeDocument $mdfe, string $accessKey, ?string $protocol = null): MdfeDocument
    {
        $accessKey = preg_replace('/\D/', '', $accessKey) ?? '';

        if (strlen($accessKey) !== 44) {
            throw new DomainException('A chave de acesso do MDF-e deve ter 44 dígitos.');
        }

        if ($mdfe->status !== CteDocumentStatusEnum::RECONCILIATION_REQUIRED) {
            throw new DomainException('Somente MDF-es em situação de reconciliação podem ser conciliados manualmente.');
        }

        $series = substr($accessKey, 22, 3);
        $number = substr($accessKey, 25, 9);

        $mdfe->forceFill([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'access_key' => $accessKey,
            'mdfe_number' => $number,
            'series' => $series,
            'protocol' => $protocol,
            'fiscal_status_code' => '100',
            'fiscal_status_message' => 'Autorizado uso do MDF-e (conciliação manual).',
            'authorized_at' => $mdfe->authorized_at ?? now(),
        ])->save();

        activity()
            ->performedOn($mdfe)
            ->log("MDF-e conciliado manualmente: nº {$number}, série {$series}.");

        return $mdfe->refresh();
    }
}

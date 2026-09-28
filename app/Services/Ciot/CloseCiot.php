<?php

namespace App\Services\Ciot;

use App\Enums\CiotOperationTypeEnum;
use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use DomainException;

class CloseCiot
{
    public function handle(Ciot $ciot): Ciot
    {
        $fullNumber = $ciot->fullNumber();

        if ($ciot->status !== CiotStatusEnum::ISSUED || $fullNumber === null) {
            throw new DomainException('Somente CIOTs emitidos podem ser encerrados.');
        }

        // Rejeição 274 da ANTT (25/09/2026): o peso é PROIBIDO no encerramento
        // de carga fracionada e obrigatório apenas na lotação (spec §2.2).
        $pesoCarga = $ciot->operation_type === CiotOperationTypeEnum::Lotation
            ? (float) $ciot->cargo_weight_kg
            : null;

        if ($ciot->operation_type === CiotOperationTypeEnum::Lotation && $pesoCarga === null) {
            throw new DomainException('O encerramento exige o peso da carga: preencha o peso no CIOT antes de encerrar.');
        }

        $response = app(AnttCiotClient::class)->encerrar($fullNumber, $pesoCarga);

        if (! $response->isSuccess() || blank($response->dataEncerramento())) {
            throw new AnttCiotException(
                sprintf('Encerramento rejeitado pela ANTT (código %s): %s', $response->codigo() ?? '?', $response->mensagem()),
                codigo: $response->codigo(),
                mensagem: $response->mensagem(),
                httpStatus: $response->httpStatus,
                body: $response->body,
            );
        }

        return $ciot->transitionFrom(CiotStatusEnum::ISSUED, CiotStatusEnum::CLOSED, fn (Ciot $locked): array => [
            'closed_at' => now(),
            'response' => array_merge($locked->response ?? [], ['encerramento' => $response->body]),
        ]) ?? $ciot->refresh();
    }
}

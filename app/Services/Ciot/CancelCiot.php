<?php

namespace App\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use DomainException;

class CancelCiot
{
    public function handle(Ciot $ciot, string $motivo): Ciot
    {
        $fullNumber = $ciot->fullNumber();

        if ($ciot->status !== CiotStatusEnum::ISSUED || $fullNumber === null) {
            throw new DomainException('Somente CIOTs emitidos podem ser cancelados.');
        }

        $motivo = trim($motivo);

        if ($motivo === '' || mb_strlen($motivo) > 500) {
            throw new DomainException('O motivo do cancelamento é obrigatório e deve ter até 500 caracteres.');
        }

        $response = app(AnttCiotClient::class)->cancel($fullNumber, $motivo);

        if (! $response->isSuccess() || blank($response->dataCancelamento())) {
            throw new AnttCiotException(
                sprintf('Cancelamento rejeitado pela ANTT (código %s): %s', $response->codigo() ?? '?', $response->mensagem()),
                codigo: $response->codigo(),
                mensagem: $response->mensagem(),
                httpStatus: $response->httpStatus,
                body: $response->body,
            );
        }

        return $ciot->transitionFrom(CiotStatusEnum::ISSUED, CiotStatusEnum::CANCELED, fn (Ciot $locked): array => [
            'cancel_reason' => $motivo,
            'canceled_at' => now(),
            'response' => array_merge($locked->response ?? [], ['cancelamento' => $response->body]),
        ]) ?? $ciot->refresh();
    }
}

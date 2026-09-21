<?php

namespace App\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use DomainException;
use Illuminate\Support\Facades\DB;

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

        if (! $response->isAccepted()) {
            throw new AnttCiotException(
                sprintf('Cancelamento rejeitado pela ANTT (HTTP %d): %s', $response->httpStatus, $response->mensagem()),
                codigo: $response->codigo(),
                mensagem: $response->mensagem(),
                httpStatus: $response->httpStatus,
                body: $response->body,
            );
        }

        return DB::transaction(function () use ($ciot, $motivo, $response): Ciot {
            $ciot = Ciot::query()
                ->whereKey($ciot->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($ciot->status !== CiotStatusEnum::ISSUED) {
                return $ciot;
            }

            $ciot->forceFill([
                'status' => CiotStatusEnum::CANCELED,
                'cancel_reason' => $motivo,
                'canceled_at' => now(),
                'response' => array_merge($ciot->response ?? [], ['cancelamento' => $response->body]),
            ])->save();

            return $ciot;
        });
    }
}

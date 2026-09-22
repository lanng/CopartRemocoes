<?php

namespace App\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use DomainException;
use Illuminate\Support\Facades\DB;

class CloseCiot
{
    public function handle(Ciot $ciot): Ciot
    {
        $fullNumber = $ciot->fullNumber();

        if ($ciot->status !== CiotStatusEnum::ISSUED || $fullNumber === null) {
            throw new DomainException('Somente CIOTs emitidos podem ser encerrados.');
        }

        $pesoCarga = $ciot->cargo_weight_kg !== null ? (float) $ciot->cargo_weight_kg : null;

        if ($pesoCarga === null) {
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

        return DB::transaction(function () use ($ciot, $response): Ciot {
            $ciot = Ciot::query()
                ->whereKey($ciot->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($ciot->status !== CiotStatusEnum::ISSUED) {
                return $ciot;
            }

            $ciot->forceFill([
                'status' => CiotStatusEnum::CLOSED,
                'closed_at' => now(),
                'response' => array_merge($ciot->response ?? [], ['encerramento' => $response->body]),
            ])->save();

            return $ciot;
        });
    }
}

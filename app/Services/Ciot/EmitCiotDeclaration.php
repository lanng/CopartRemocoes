<?php

namespace App\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Jobs\EmitCiotJob;
use App\Models\Ciot;
use DomainException;
use Illuminate\Support\Facades\DB;

class EmitCiotDeclaration
{
    /**
     * Prepara o payload, persiste a chave de idempotência e enfileira a emissão.
     */
    public function handle(Ciot $ciot): Ciot
    {
        return DB::transaction(function () use ($ciot): Ciot {
            $ciot = Ciot::query()
                ->whereKey($ciot->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($ciot->status, [CiotStatusEnum::DRAFT, CiotStatusEnum::FAILED], true)) {
                throw new DomainException('Somente CIOTs em rascunho ou com falha podem ser emitidos.');
            }

            $payload = app(BuildCiotDeclarationPayload::class)->handle($ciot);

            $ciot->forceFill([
                'id_operacao_transporte' => $payload['IdOperacaoTransporte'],
                'payload' => $payload,
                'status' => CiotStatusEnum::PENDING,
                'error_code' => null,
                'error_message' => null,
                'response' => null,
            ])->save();

            EmitCiotJob::dispatch($ciot->id);

            return $ciot;
        });
    }
}

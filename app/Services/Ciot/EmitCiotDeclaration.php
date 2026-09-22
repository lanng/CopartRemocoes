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
     * Valida as regras, obtém o IdOperacaoTransporte do servidor ANTT
     * (nunca inventado pelo cliente — spec §2.1), persiste e enfileira.
     */
    public function handle(Ciot $ciot): Ciot
    {
        $payload = app(BuildCiotDeclarationPayload::class)->handle($ciot);

        $idOperacaoTransporte = app(AnttCiotClient::class)->generateIdOperacaoTransporte();

        $payload['IdOperacaoTransporte'] = $idOperacaoTransporte;

        return DB::transaction(function () use ($ciot, $payload, $idOperacaoTransporte): Ciot {
            $ciot = Ciot::query()
                ->whereKey($ciot->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($ciot->status, [CiotStatusEnum::DRAFT, CiotStatusEnum::FAILED], true)) {
                throw new DomainException('Somente CIOTs em rascunho ou com falha podem ser emitidos.');
            }

            $ciot->forceFill([
                'id_operacao_transporte' => $idOperacaoTransporte,
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

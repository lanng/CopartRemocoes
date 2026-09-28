<?php

namespace App\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Jobs\EmitCiotJob;
use App\Models\Ciot;

class EmitCiotDeclaration
{
    /**
     * Valida as regras, obtém o IdOperacaoTransporte do servidor ANTT
     * (nunca inventado pelo cliente — spec §2.1), persiste e enfileira.
     * Com `sync = true` (botões "Criar e emitir"/"Salvar e emitir"), envia a
     * declaração na hora: o resultado fica imediatamente no registro. Erros
     * retryable sobem para o chamador reenfileirar.
     */
    public function handle(Ciot $ciot, bool $sync = false): Ciot
    {
        $payload = app(BuildCiotDeclarationPayload::class)->handle($ciot);

        $idOperacaoTransporte = app(AnttCiotClient::class)->generateIdOperacaoTransporte();

        $payload['IdOperacaoTransporte'] = $idOperacaoTransporte;

        $ciot = $ciot->transitionTo(CiotStatusEnum::PENDING, [
            'id_operacao_transporte' => $idOperacaoTransporte,
            'payload' => $payload,
            'error_code' => null,
            'error_message' => null,
            'response' => null,
        ], guardMessage: 'Somente CIOTs em rascunho ou com falha podem ser emitidos.');

        if ($sync) {
            return app(DeclareCiot::class)->handle($ciot);
        }

        EmitCiotJob::dispatch($ciot->id);

        return $ciot;
    }
}

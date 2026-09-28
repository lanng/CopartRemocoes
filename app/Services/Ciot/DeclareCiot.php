<?php

namespace App\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Models\Ciot;

/**
 * Envia a declaração pendente à ANTT e grava o resultado (emitido/falha).
 * Compartilhado pela fila (EmitCiotJob, com retry) e pela emissão síncrona
 * do painel ("Criar e emitir"/"Salvar e emitir").
 */
class DeclareCiot
{
    public function handle(Ciot $ciot): Ciot
    {
        $ciot = $ciot->refresh() ?? $ciot;

        if ($ciot->status !== CiotStatusEnum::PENDING || empty($ciot->payload)) {
            return $ciot;
        }

        try {
            $response = app(AnttCiotClient::class)->declare($ciot->payload);
        } catch (AnttCiotException $exception) {
            if ($exception->retryable()) {
                throw $exception;
            }

            return $this->markFailed($ciot, $exception->codigo, $exception->mensagem ?? $exception->getMessage());
        }

        if (! $response->isSuccess()) {
            return $this->markFailed(
                $ciot,
                $response->codigo(),
                $response->mensagem() ?? json_encode($response->body, JSON_UNESCAPED_UNICODE),
            );
        }

        $ciot = $ciot->transitionFrom(CiotStatusEnum::PENDING, CiotStatusEnum::ISSUED, fn (Ciot $locked): array => [
            'ciot_number' => $response->identificacaoOperacao(),
            'verifier_code' => $response->codigoVerificador(),
            'protocol' => $response->protocolo(),
            'carrier_notice' => $response->avisoTransportador(),
            'issued_at' => now(),
            'response' => $response->body,
            'error_code' => null,
            'error_message' => null,
        ]) ?? $ciot->refresh();

        // Viagem completa + CIOT emitido: despacha o MDF-e (idempotente — os
        // guards internos pulam se algo ainda falta, spec mdfe-agent-payload).
        if ($ciot->cte_emission_batch_id !== null) {
            app(DispatchMdfeForBatch::class)->handle($ciot->cteEmissionBatch);
        }

        return $ciot;
    }

    protected function markFailed(Ciot $ciot, ?string $codigo, string $mensagem): Ciot
    {
        return $ciot->transitionFrom(CiotStatusEnum::PENDING, CiotStatusEnum::FAILED, fn (): array => [
            'error_code' => $codigo,
            'error_message' => $mensagem,
        ]) ?? $ciot->refresh();
    }
}

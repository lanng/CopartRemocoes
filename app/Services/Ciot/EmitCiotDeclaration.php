<?php

namespace App\Services\Ciot;

use App\Enums\CiotEmissionOutcome;
use App\Enums\CiotStatusEnum;
use App\Jobs\EmitCiotJob;
use App\Models\Ciot;
use DomainException;
use Throwable;

class EmitCiotDeclaration
{
    /**
     * Entrada síncrona (botões "criar/salvar/emitir"): tenta emitir na hora
     * e devolve o outcome. Exceções retryable após os side-effects
     * persistidos são reportadas e reenfileiradas (Queued); violações de
     * regra/guard devolvem Invalid sem `report()` — resultado esperado,
     * não erro.
     */
    public function emit(Ciot $ciot): CiotEmissionResult
    {
        try {
            $ciot = $this->attempt($ciot);
        } catch (DomainException $exception) {
            return new CiotEmissionResult(CiotEmissionOutcome::Invalid, $ciot->refresh(), $exception->getMessage());
        } catch (AnttCiotException $exception) {
            // Somente retryable escapa de DeclareCiot — non-retryable aqui
            // veio do prepare (geração do IdOperacaoTransporte), sem efeitos.
            if (! $exception->retryable()) {
                return new CiotEmissionResult(
                    CiotEmissionOutcome::Invalid,
                    $ciot->refresh(),
                    $exception->mensagem ?? $exception->getMessage(),
                );
            }

            report($exception);

            return $this->queue($ciot, CiotEmissionOutcome::Queued);
        } catch (Throwable $exception) {
            report($exception);

            return $this->queue($ciot, CiotEmissionOutcome::Queued);
        }

        return new CiotEmissionResult(
            $ciot->status === CiotStatusEnum::ISSUED ? CiotEmissionOutcome::Issued : CiotEmissionOutcome::Failed,
            $ciot,
            $ciot->error_message,
        );
    }

    /**
     * Entrada assíncrona (ação da tabela): apenas o prepare roda síncrono;
     * a declaração vai para a fila. Falhas do prepare são Invalid (sem
     * `report()`), erros inesperados são Errored (reportados).
     */
    public function enqueue(Ciot $ciot): CiotEmissionResult
    {
        try {
            $ciot = $this->prepareIfNeeded($ciot);
        } catch (DomainException $exception) {
            return new CiotEmissionResult(CiotEmissionOutcome::Invalid, $ciot->refresh(), $exception->getMessage());
        } catch (AnttCiotException $exception) {
            return new CiotEmissionResult(
                CiotEmissionOutcome::Invalid,
                $ciot->refresh(),
                $exception->mensagem ?? $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return new CiotEmissionResult(CiotEmissionOutcome::Errored, $ciot->refresh(), $exception->getMessage());
        }

        EmitCiotJob::dispatch($ciot->id);

        return new CiotEmissionResult(CiotEmissionOutcome::Enqueued, $ciot);
    }

    /**
     * Contrato bruto compartilhado por `emit()` e `EmitCiotJob`: prepara o
     * registro quando necessário (status em [DRAFT, FAILED] ou payload
     * vazio — FAILED sempre regenera o IdOperacaoTransporte) e envia a
     * declaração. Não captura nada.
     */
    public function attempt(Ciot $ciot): Ciot
    {
        $ciot = $this->prepareIfNeeded($ciot);

        return app(DeclareCiot::class)->handle($ciot);
    }

    /**
     * Valida as regras, obtém o IdOperacaoTransporte do servidor ANTT
     * (nunca inventado pelo cliente — spec §2.1) e grava o PENDING.
     * PENDING com payload pula direto (já preparado).
     */
    protected function prepareIfNeeded(Ciot $ciot): Ciot
    {
        if ($ciot->status === CiotStatusEnum::PENDING && ! empty($ciot->payload)) {
            return $ciot;
        }

        $payload = app(BuildCiotDeclarationPayload::class)->handle($ciot);

        $idOperacaoTransporte = app(AnttCiotClient::class)->generateIdOperacaoTransporte();

        $payload['IdOperacaoTransporte'] = $idOperacaoTransporte;

        return $ciot->transitionTo(CiotStatusEnum::PENDING, [
            'id_operacao_transporte' => $idOperacaoTransporte,
            'payload' => $payload,
            'error_code' => null,
            'error_message' => null,
            'response' => null,
        ], guardMessage: 'Somente CIOTs em rascunho ou com falha podem ser emitidos.');
    }

    protected function queue(Ciot $ciot, CiotEmissionOutcome $outcome): CiotEmissionResult
    {
        EmitCiotJob::dispatch($ciot->id);

        return new CiotEmissionResult($outcome, $ciot->refresh());
    }
}

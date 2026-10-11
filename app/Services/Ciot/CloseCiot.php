<?php

namespace App\Services\Ciot;

use App\Enums\CiotOperationTypeEnum;
use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use DomainException;
use Illuminate\Support\Facades\DB;

class CloseCiot
{
    public function handle(Ciot $ciot): Ciot
    {
        if ($ciot->isSyntheticHomologation()) {
            throw new DomainException('CIOT sintético de homologação não pode ser encerrado pela ANTT.');
        }

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

        try {
            $response = app(AnttCiotClient::class)->encerrar($fullNumber, $pesoCarga);
        } catch (AnttCiotException $exception) {
            $this->recordFailedAttempt($ciot, $exception);

            throw $exception;
        }

        if (! $response->isSuccess() || blank($response->dataEncerramento())) {
            $exception = new AnttCiotException(
                sprintf('Encerramento rejeitado pela ANTT (código %s): %s', $response->codigo() ?? '?', $response->mensagem()),
                codigo: $response->codigo(),
                mensagem: $response->mensagem(),
                httpStatus: $response->httpStatus,
                body: $response->body,
            );

            $this->recordFailedAttempt($ciot, $exception);

            throw $exception;
        }

        $ciot = $ciot->transitionFrom(CiotStatusEnum::ISSUED, CiotStatusEnum::CLOSED, fn (Ciot $locked): array => [
            'closed_at' => now(),
            'response' => $this->responseAfterSuccessfulClosure($locked, $response->body),
        ]) ?? $ciot->refresh();

        app(\App\Services\Dfe\ReserveMdfeDocuments::class)->releaseCiotIfTerminal($ciot);

        return $ciot;
    }

    private function recordFailedAttempt(Ciot $ciot, AnttCiotException $exception): void
    {
        DB::transaction(function () use ($ciot, $exception): void {
            $lockedCiot = Ciot::query()
                ->whereKey($ciot->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCiot->status !== CiotStatusEnum::ISSUED) {
                return;
            }

            $response = $lockedCiot->response ?? [];
            $response['ultima_tentativa_encerramento'] = [
                'resultado' => $exception->retryable() ? 'inconclusivo' : 'rejeitado',
                'tentado_em' => now()->toIso8601String(),
                'codigo' => $exception->codigo,
                'mensagem' => $exception->mensagem,
                'http_status' => $exception->httpStatus,
                'erro' => $exception->getMessage(),
                'body' => $exception->body,
            ];

            $lockedCiot->forceFill(['response' => $response])->save();
        });

        $ciot->refresh();
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function responseAfterSuccessfulClosure(Ciot $ciot, array $body): array
    {
        $response = $ciot->response ?? [];
        unset($response['ultima_tentativa_encerramento']);
        $response['encerramento'] = $body;

        return $response;
    }
}

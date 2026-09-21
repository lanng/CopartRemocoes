<?php

namespace App\Jobs;

use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use App\Services\Ciot\AnttCiotClient;
use App\Services\Ciot\AnttCiotException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class EmitCiotJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $ciotId) {}

    public function uniqueId(): string
    {
        return (string) $this->ciotId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ciot-emission:'.$this->ciotId))
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(AnttCiotClient $client): void
    {
        $ciot = Ciot::query()->find($this->ciotId);

        if ($ciot === null || $ciot->status !== CiotStatusEnum::PENDING || empty($ciot->payload)) {
            return;
        }

        try {
            $response = $client->declare($ciot->payload);
        } catch (AnttCiotException $exception) {
            if ($exception->retryable()) {
                throw $exception;
            }

            $this->markFailed($exception->codigo, $exception->mensagem ?? $exception->getMessage());

            return;
        }

        if ($response->isSuccess()) {
            DB::transaction(function () use ($ciot, $response): void {
                $ciot = Ciot::query()
                    ->whereKey($ciot->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($ciot->status !== CiotStatusEnum::PENDING) {
                    return;
                }

                $ciot->forceFill([
                    'status' => CiotStatusEnum::ISSUED,
                    'ciot_number' => $response->identificacaoOperacao(),
                    'verifier_code' => $response->codigoVerificador(),
                    'protocol' => $response->protocolo(),
                    'carrier_notice' => $response->avisoTransportador(),
                    'issued_at' => now(),
                    'response' => $response->body,
                    'error_code' => null,
                    'error_message' => null,
                ])->save();
            });

            return;
        }

        $this->markFailed(
            $response->codigo(),
            $response->mensagem() ?? json_encode($response->body, JSON_UNESCAPED_UNICODE),
        );
    }

    public function failed(Throwable $exception): void
    {
        $ciot = Ciot::query()->find($this->ciotId);

        if ($ciot !== null && $ciot->status === CiotStatusEnum::PENDING) {
            $ciot->forceFill([
                'status' => CiotStatusEnum::FAILED,
                'error_code' => 'job_exhausted',
                'error_message' => $exception->getMessage(),
            ])->save();
        }
    }

    protected function markFailed(?string $codigo, string $mensagem): void
    {
        Ciot::query()
            ->whereKey($this->ciotId)
            ->where('status', CiotStatusEnum::PENDING->value)
            ->update([
                'status' => CiotStatusEnum::FAILED->value,
                'error_code' => $codigo,
                'error_message' => $mensagem,
            ]);
    }
}

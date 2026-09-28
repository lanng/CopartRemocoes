<?php

namespace App\Jobs;

use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use App\Services\Ciot\EmitCiotDeclaration;
use DomainException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    public function handle(EmitCiotDeclaration $emitter): void
    {
        $ciot = Ciot::query()->find($this->ciotId);

        if ($ciot === null) {
            return;
        }

        try {
            $emitter->attempt($ciot);
        } catch (DomainException $exception) {
            // Terminal: violação de regra/guard não melhora com retry —
            // marca FAILED sem queimar as 3 tentativas + backoff.
            if ($ciot->status === CiotStatusEnum::FAILED) {
                $ciot->forceFill(['error_message' => $exception->getMessage()])->save();

                return;
            }

            $ciot->transitionTo(CiotStatusEnum::FAILED, [
                'error_code' => null,
                'error_message' => $exception->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $ciot = Ciot::query()->find($this->ciotId);

        if ($ciot !== null) {
            $fields = fn (): array => [
                'error_code' => 'job_exhausted',
                'error_message' => $exception->getMessage(),
            ];

            $ciot->transitionFrom(CiotStatusEnum::PENDING, CiotStatusEnum::FAILED, $fields)
                ?? $ciot->transitionFrom(CiotStatusEnum::DRAFT, CiotStatusEnum::FAILED, $fields);
        }
    }
}

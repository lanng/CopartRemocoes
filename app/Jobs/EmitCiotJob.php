<?php

namespace App\Jobs;

use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use App\Services\Ciot\DeclareCiot;
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

    public function handle(DeclareCiot $declarer): void
    {
        $ciot = Ciot::query()->find($this->ciotId);

        if ($ciot === null) {
            return;
        }

        $declarer->handle($ciot);
    }

    public function failed(Throwable $exception): void
    {
        $ciot = Ciot::query()->find($this->ciotId);

        if ($ciot !== null && $ciot->status === CiotStatusEnum::PENDING) {
            $ciot->forceFill([
                'status' => CiotStatusEnum::FAILED->value,
                'error_code' => 'job_exhausted',
                'error_message' => $exception->getMessage(),
            ])->save();
        }
    }
}

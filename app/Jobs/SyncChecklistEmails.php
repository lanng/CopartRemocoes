<?php

namespace App\Jobs;

use App\Services\MicrosoftGraph\SyncChecklistEmailsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncChecklistEmails implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('microsoft-graph-checklist-sync'))
                ->dontRelease()
                ->expireAfter(60),
        ];
    }

    public function handle(SyncChecklistEmailsService $sync): void
    {
        try {
            $sync->handle();
        } catch (\Throwable $exception) {
            throw $exception;
        }
    }
}

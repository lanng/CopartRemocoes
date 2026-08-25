<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessRemovalRequestEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $integrationInboxItemId) {}

    public function handle(): void {}
}

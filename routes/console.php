<?php

use App\Jobs\SyncChecklistEmails;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:cleanup-old-registers')->dailyAt('03:00');
Schedule::command('app:cleanup-integration-inbox-items')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('payments:generate-pending-batches')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('payments:cleanup-batches')->dailyAt('03:45')->withoutOverlapping();
Schedule::job(new SyncChecklistEmails)->everyFiveMinutes()->withoutOverlapping();

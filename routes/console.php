<?php

use App\Jobs\SyncChecklistEmails;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:cleanup-old-registers')
    ->dailyAt('09:00')
    ->timezone('America/Sao_Paulo')
    ->appendOutputTo(storage_path('logs/schedule-cleanup-old-registers.log'));
Schedule::command('app:cleanup-integration-inbox-items')
    ->dailyAt('09:15')
    ->withoutOverlapping()
    ->timezone('America/Sao_Paulo')
    ->appendOutputTo(storage_path('logs/schedule-cleanup-integration-inbox-items.log'));
Schedule::command('payments:generate-pending-batches')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->timezone('America/Sao_Paulo')
    ->appendOutputTo(storage_path('logs/schedule-generate-pending-batches.log'));
Schedule::command('payments:cleanup-batches')
    ->dailyAt('09:30')
    ->withoutOverlapping()
    ->timezone('America/Sao_Paulo')
    ->appendOutputTo(storage_path('logs/schedule-cleanup-batches.log'));
Schedule::job(new SyncChecklistEmails)->everyFiveMinutes()->withoutOverlapping();

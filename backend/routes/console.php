<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Modules\Inventory\Services\StockReservationService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// inventory:expire-reservations is auto-registered by App\Console\Kernel::load().
// The class App\Console\Commands\ExpireReservations defines the command.

// Financial Reporting Module - Scheduled Jobs
Schedule::command('reports:process-schedules')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('reports:clear-cache')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

// Inventory reservation expiry (RM-001 runtime closure gate)
Schedule::command('inventory:expire-reservations')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// POS Audit Log Retention - Prune logs older than configured retention period
Schedule::command('audit:prune')
    ->monthly()
    ->withoutOverlapping()
    ->runInBackground();

// POS Reconciliation - Daily reconciliation report (fail-on-exceptions for alerting)
Schedule::command('pos:reconciliation-report --fail-on-exceptions')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground();

// POS Recovery - Check for stuck orders every 15 minutes
Schedule::command('pos:recover-stuck --fix-syncing')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

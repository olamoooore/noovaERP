<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

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

// Issue #912 - tamper-evident audit hash chain: capture a signed anchor of
// the chain head per tenant/day, then verify the whole chain and raise
// platform exceptions on any break.
Schedule::command('audit:anchor')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('audit:verify-chain')
    ->dailyAt('01:45')
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

// #666 EI4: poll e-invoice status so Invalid results and buyer rejection
// requests are noticed instead of lapsing while submissions sit "submitted".
Schedule::command('compliance:sync-einvoice-status')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Issue #151 - Recurring/subscription sales order generation.
Schedule::command('sales:generate-recurring-orders')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();

// Issue #109 - Inventory subledger vs GL control-account reconciliation.
Schedule::command('inventory:reconcile-gl')
    ->dailyAt('05:45')
    ->withoutOverlapping()
    ->runInBackground();

// Issue #881 - AR subledger vs GL AR control account, per legal entity.
// --fail-on-variance makes an out-of-tolerance day a failed run (alertable).
Schedule::command('accounting:reconcile-ar-gl --fail-on-variance')
    ->dailyAt('05:50')
    ->withoutOverlapping()
    ->runInBackground();

// Issue #102 - Exchange-rate sync. The FX revaluation side of the same issue
// is wired into PeriodCloseService::runAutomaticAdjustments(), so closing a
// period with open FX balances posts the unrealised gain/loss automatically.
Schedule::command('exchange-rates:sync')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground();

// Accounting Outbox (issue #62) - Publish written-but-unpublished ledger
// events onto the Domain Event Bus every minute. withoutOverlapping +
// SKIP LOCKED inside the command make this safe to double-run.
Schedule::command('accounting:publish-outbox')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

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

// #667 EI5: daily digest to finance of posted invoices whose e-invoice is not
// yet Valid (queued, failed, rejected, awaiting validation).
Schedule::command('compliance:send-einvoice-digest')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->runInBackground();

// Issue #151 - Recurring/subscription sales order generation.
Schedule::command('sales:generate-recurring-orders')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();

// Issue #882 - deferred revenue: release the portion of each active schedule
// earned by the first of the month to the line's income account. The posting
// goes through PostingEngine and accumulates the schedule's recognized amount.
Schedule::command('accounting:recognize-deferred-revenue')
    ->monthlyOn(1, '03:30')
    ->withoutOverlapping()
    ->runInBackground();

// Issue #606 - weekly per-salesperson sales digest. The command iterates every
// tenant (IteratesTenants) and sends only to users who opted in.
Schedule::command('sales:send-weekly-digest')
    ->weeklyOn(1, '07:00')
    ->withoutOverlapping()
    ->runInBackground();

// Issue #598 (RPT-UX3) - scheduled saved report views. The command iterates
// every tenant (IteratesTenants) and sends each due schedule (daily / weekly /
// monthly next_run_at) to recipients who still hold reports.view and entity
// scope; unauthorized recipients are logged as skipped, never emailed.
Schedule::command('reports:send-scheduled-views')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Issue #109 - Inventory subledger vs GL control-account reconciliation.
Schedule::command('inventory:reconcile-gl')
    ->dailyAt('05:45')
    ->withoutOverlapping()
    ->runInBackground();

// Issue #696 - nightly per-document stock & COGS reconciliation badge sweep.
// Records each mismatch once in #902's platform exception queue (accountant
// owner) and fails the run when anything was found, so the scheduler alerting
// sees it; the fix is the document's existing reversal / reset-to-draft path.
Schedule::command('inventory:stock-cogs-reconciliation-report --fail-on-mismatch')
    ->dailyAt('05:55')
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

// Sales Outbox (issue #901, PC7) - Publish written-but-unpublished sales
// domain events onto the Domain Event Bus. Same contract as the accounting
// outbox: withoutOverlapping + SKIP LOCKED make it safe to double-run.
Schedule::command('sales:publish-outbox')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Issue #903 (PC9) - time-based sales notifications. The #901 catalogue only
// emits invoice.overdue on a settlement transition and quotation.expired on a
// transition, so these daily scans notify the account owner when an invoice
// passes its due date and warn quotation owners before validity lapses. Both
// are idempotent per document through the notification dispatcher.
Schedule::command('sales:scan-overdue-invoices')
    ->dailyAt('06:10')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('sales:scan-expiring-quotations')
    ->dailyAt('06:20')
    ->withoutOverlapping()
    ->runInBackground();

// Issue #335 (Q6) - validity expiry. The daily sweep marks draft/sent
// quotations whose valid_until has passed as `expired` through the #900
// engine (audited), so they can no longer be confirmed or converted until
// re-validated. The command is idempotent and iterates every tenant.
Schedule::command('quotations:expire')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->runInBackground();

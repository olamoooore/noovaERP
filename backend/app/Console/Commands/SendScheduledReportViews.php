<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\FinancialReporting\Models\ReportViewScheduleLog;
use Modules\FinancialReporting\Services\ScheduledSavedViewDeliveryService;

/**
 * Issue #598 (RPT-UX3) — send due scheduled saved report views.
 *
 * Iterates every tenant (IteratesTenants) because `report_view_schedules`
 * lives in each tenant schema; within a tenant it processes every schedule
 * whose next_run_at is due and whose is_active flag is set. Recipients are
 * re-authorised at send time by ScheduledSavedViewDeliveryService, and every
 * skip/failure is printed here as well as logged in the tenant schema.
 */
class SendScheduledReportViews extends Command
{
    use IteratesTenants;

    protected $signature = 'reports:send-scheduled-views
        {--tenant= : Run for a single tenant id instead of iterating every tenant}';

    protected $description = 'Send due scheduled saved report views (CSV/PDF) to their permitted recipients (#598)';

    public function handle(): int
    {
        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant !== null && $this->schemaExists($tenant->getSchemaName())) {
                return (int) $tenant->run(fn () => $this->deliverTenant($tenant, Carbon::now()));
            }

            // Tests and single-schema CLI contexts have no per-tenant schema
            // to switch to: run against the current search_path (same fallback
            // as SendWeeklySalesDigest).
            return $this->deliverTenant($tenant ?? new Tenant(['id' => $tenantId]), Carbon::now());
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->deliverTenant($tenant, Carbon::now()));
    }

    private function deliverTenant(Tenant $tenant, Carbon $now): int
    {
        $tenantKey = (string) $tenant->getTenantKey();

        if (! Schema::hasTable('report_view_schedules')) {
            $this->info("[tenant:{$tenantKey}] report_view_schedules table not present; skipping.");

            return 0;
        }

        $lastLogId = (int) ReportViewScheduleLog::query()->max('id');

        $counts = app(ScheduledSavedViewDeliveryService::class)->runDueSchedules($tenantKey, $now);

        // Every recipient (sent, skipped or failed) has a log row; surface the
        // non-sent outcomes on the console so an unauthorized recipient is
        // visibly skipped, not silently dropped.
        foreach (ReportViewScheduleLog::query()->where('id', '>', $lastLogId)->get() as $log) {
            if ($log->status === ReportViewScheduleLog::STATUS_SENT) {
                continue;
            }

            $this->warn(sprintf(
                '[tenant:%s] schedule #%d — %s %s%s',
                $tenantKey,
                $log->schedule_id,
                (string) ($log->recipient ?? ('user '.$log->user_id)),
                $log->status,
                $log->reason ? ': '.$log->reason : '',
            ));
        }

        $this->info(sprintf(
            '[tenant:%s] Scheduled saved views — processed: %d, sent: %d, skipped: %d, failed: %d',
            $tenantKey,
            $counts['processed'],
            $counts['sent'],
            $counts['skipped'],
            $counts['failed'],
        ));

        return $counts['failed'] > 0 ? 1 : 0;
    }
}

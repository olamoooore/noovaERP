<?php

namespace Modules\FinancialReporting\Console;

use App\Console\Concerns\IteratesTenants;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\FinancialReporting\Jobs\GenerateScheduledReport;
use Modules\FinancialReporting\Models\ReportSchedule;

/**
 * Console command to process scheduled reports.
 * Should be run via Laravel scheduler (cron).
 *
 * Per Pillar 1 P0-11 / Pillar 2 Part 1 §1.3-§1.4: the report_schedules
 * table is tenant-schema-only. The scheduler runs this with the
 * default `search_path = 'public'`, so without per-tenant iteration
 * the query fails with SQLSTATE[42P01]. We now use IteratesTenants.
 */
class ProcessScheduledReports extends Command
{
    use IteratesTenants;

    protected $signature = 'reports:process-schedules';

    protected $description = 'Process scheduled reports that are due to run (per-tenant)';

    public function handle(): int
    {
        $this->info('Processing scheduled reports across all tenants...');

        return $this->forEachTenant(function ($tenant) {
            $this->info("[tenant:{$tenant->id}] Processing scheduled reports...");

            $now = Carbon::now();

            $schedules = ReportSchedule::where('is_active', true)
                ->where(function ($query) use ($now) {
                    $query->whereNull('next_run_at')
                        ->orWhere('next_run_at', '<=', $now);
                })
                ->get();

            if ($schedules->isEmpty()) {
                $this->info("[tenant:{$tenant->id}] No schedules due to run.");

                return Command::SUCCESS;
            }

            $this->info("[tenant:{$tenant->id}] Found {$schedules->count()} schedule(s) to process.");

            foreach ($schedules as $schedule) {
                try {
                    $this->info("[tenant:{$tenant->id}] Dispatching job for schedule: {$schedule->name} (ID: {$schedule->id})");
                    GenerateScheduledReport::dispatch($schedule);
                    $this->info("✓ Job dispatched successfully");
                } catch (\Exception $e) {
                    $this->error("✗ Failed to dispatch job: {$e->getMessage()}");
                }
            }

            $this->info("[tenant:{$tenant->id}] Scheduled reports processing complete.");

            return Command::SUCCESS;
        });
    }
}

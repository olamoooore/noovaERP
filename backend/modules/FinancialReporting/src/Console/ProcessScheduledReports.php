<?php

namespace Modules\FinancialReporting\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\FinancialReporting\Jobs\GenerateScheduledReport;
use Modules\FinancialReporting\Models\ReportSchedule;

/**
 * Console command to process scheduled reports
 * Should be run via Laravel scheduler (cron)
 */
class ProcessScheduledReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:process-schedules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process scheduled reports that are due to run';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Processing scheduled reports...');

        $now = Carbon::now();

        // Get all active schedules that are due to run
        $schedules = ReportSchedule::where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', $now);
            })
            ->get();

        if ($schedules->isEmpty()) {
            $this->info('No schedules due to run.');

            return Command::SUCCESS;
        }

        $this->info("Found {$schedules->count()} schedule(s) to process.");

        foreach ($schedules as $schedule) {
            try {
                $this->info("Dispatching job for schedule: {$schedule->name} (ID: {$schedule->id})");

                // Dispatch the job
                GenerateScheduledReport::dispatch($schedule);

                $this->info('✓ Job dispatched successfully');
            } catch (\Exception $e) {
                $this->error("✗ Failed to dispatch job: {$e->getMessage()}");
            }
        }

        $this->info('Scheduled reports processing complete.');

        return Command::SUCCESS;
    }
}

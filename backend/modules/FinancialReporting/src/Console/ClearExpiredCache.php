<?php

namespace Modules\FinancialReporting\Console;

use Illuminate\Console\Command;
use Modules\FinancialReporting\Jobs\ClearReportCache;

/**
 * Console command to clear expired report cache
 * Should be scheduled to run daily
 */
class ClearExpiredCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:clear-cache {--all : Clear all cache, not just expired}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear expired report cache entries';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $clearAll = $this->option('all');

        if ($clearAll) {
            $this->warn('Clearing ALL report cache...');
            if (! $this->confirm('Are you sure you want to clear all cache?')) {
                $this->info('Operation cancelled.');

                return Command::SUCCESS;
            }
        } else {
            $this->info('Clearing expired report cache...');
        }

        // Dispatch the job
        ClearReportCache::dispatch($clearAll);

        $this->info('Cache clearing job dispatched successfully.');

        return Command::SUCCESS;
    }
}

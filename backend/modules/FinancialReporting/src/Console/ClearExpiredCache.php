<?php

namespace Modules\FinancialReporting\Console;

use App\Console\Concerns\IteratesTenants;
use Illuminate\Console\Command;
use Modules\FinancialReporting\Jobs\ClearReportCache;

/**
 * Console command to clear expired report cache.
 * Should be scheduled to run daily.
 *
 * Per the original Pillar 1 review, the dispatched job already
 * requires an explicit tenant scope. We iterate over tenants so
 * each tenant's cache is cleared.
 */
class ClearExpiredCache extends Command
{
    use IteratesTenants;

    protected $signature = 'reports:clear-cache {--all : Clear all cache, not just expired}';

    protected $description = 'Clear expired report cache entries (per-tenant)';

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

        return $this->forEachTenant(function ($tenant) use ($clearAll) {
            $this->info("[tenant:{$tenant->id}] Dispatching cache-clearing job...");
            ClearReportCache::dispatch($clearAll);
            $this->info("[tenant:{$tenant->id}] Cache clearing job dispatched successfully.");
            return Command::SUCCESS;
        });
    }
}

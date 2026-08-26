<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAuditLogs extends Command
{
    use IteratesTenants;

    protected $signature = 'audit:prune
                            {--days= : Override retention days from config}
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Prune audit logs older than the configured retention period (per-tenant)';

    public function handle()
    {
        $retentionDays = $this->option('days')
            ?? config('audit.retention_days', 2555); // default 7 years
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($retentionDays);

        return $this->forEachTenant(function ($tenant) use ($retentionDays, $dryRun, $cutoff) {
            $this->info("[tenant:{$tenant->id}] Retention policy: {$retentionDays} days (cutoff: {$cutoff->toDateString()})");

            $count = DB::table('audit_logs')
                ->where('created_at', '<', $cutoff)
                ->count();

            if ($count === 0) {
                $this->info("[tenant:{$tenant->id}] No audit logs to prune.");

                return 0;
            }

            $this->info("[tenant:{$tenant->id}] Found {$count} audit log(s) older than {$retentionDays} days.");

            if ($dryRun) {
                $this->warn("[tenant:{$tenant->id}] [DRY RUN] Would delete {$count} audit log(s). No changes made.");

                return 0;
            }

            $deleted = DB::table('audit_logs')
                ->where('created_at', '<', $cutoff)
                ->delete();

            $this->info("[tenant:{$tenant->id}] Pruned {$deleted} audit log(s) older than {$retentionDays} days.");

            return 0;
        });
    }
}

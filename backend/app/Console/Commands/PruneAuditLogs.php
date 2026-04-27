<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune
                            {--days= : Override retention days from config}
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Prune audit logs older than the configured retention period';

    public function handle()
    {
        $retentionDays = $this->option('days')
            ?? config('audit.retention_days', 2555); // default 7 years

        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($retentionDays);

        $this->info("Retention policy: {$retentionDays} days (cutoff: {$cutoff->toDateString()})");

        // Count records to be pruned
        $count = DB::table('audit_logs')
            ->where('created_at', '<', $cutoff)
            ->count();

        if ($count === 0) {
            $this->info('No audit logs to prune.');
            return 0;
        }

        $this->info("Found {$count} audit log(s) older than {$retentionDays} days.");

        if ($dryRun) {
            $this->warn("[DRY RUN] Would delete {$count} audit log(s). No changes made.");
            return 0;
        }

        // Hard delete via DB query to bypass model immutability guard
        // (retention pruning is a legitimate system operation, not a user mutation)
        $deleted = DB::table('audit_logs')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} audit log(s) older than {$retentionDays} days.");

        return 0;
    }
}

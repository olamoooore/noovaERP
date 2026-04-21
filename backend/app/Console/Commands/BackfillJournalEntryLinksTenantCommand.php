<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill tenant_id for journal_entry_links
 *
 * This is a one-time migration command to populate tenant_id
 * from existing journal entries or tenant-scoped data.
 *
 * WARNING: This must be run with tenant context set.
 * Do not run in multi-tenant context.
 */
class BackfillJournalEntryLinksTenantCommand extends Command
{
    protected $signature = 'accounting:backfill-journal-entry-links-tenant
        {--tenant= : Specific tenant ID (required)}
        {--dry-run : Show what would be updated without making changes}
        {--batch=100 : Records per batch}';

    protected $description = 'Backfill tenant_id for journal_entry_links table';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        if (!$tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan accounting:backfill-journal-entry-links-tenant --tenant=<tenant-id>');
            return Command::FAILURE;
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return Command::FAILURE;
        }

        $this->info("Processing tenant: {$tenant->id}");

        return $tenant->run(function () {
            return $this->processBackfill();
        });
    }

    /**
     * Process the backfill within tenant context.
     */
    protected function processBackfill(): int
    {
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch');

        $this->info("Starting backfill (dry-run: " . ($dryRun ? 'yes' : 'no') . ')');

        $totalProcessed = 0;
        $totalSkipped = 0;

        // Get links without tenant_id in batches
        $query = DB::table('journal_entry_links')
            ->whereNull('tenant_id')
            ->orderBy('id');

        $totalWithoutTenant = $query->count();

        if ($totalWithoutTenant === 0) {
            $this->info('All journal_entry_links already have tenant_id');
            return Command::SUCCESS;
        }

        $this->info("Found {$totalWithoutTenant} records without tenant_id");

        // Process in batches
        $query->chunk($batchSize, function ($links) use ($dryRun, &$totalProcessed, &$totalSkipped) {
            $ids = collect($links)->pluck('id');

            foreach ($links as $link) {
                // Try to derive tenant_id from related journal entry
                $tenantId = $this->findTenantIdForLink($link);

                if ($tenantId) {
                    if (!$dryRun) {
                        DB::table('journal_entry_links')
                            ->where('id', $link->id)
                            ->update(['tenant_id' => $tenantId]);
                    }
                    $totalProcessed++;
                } else {
                    $totalSkipped++;
                    $this->warn("Could not derive tenant for link ID: {$link->id}");
                }
            }

            $this->line("Processed batch: {$totalProcessed} updated, {$totalSkipped} skipped");
        });

        $this->info("Backfill complete. Processed: {$totalProcessed}, Skipped: {$totalSkipped}");

        if ($dryRun) {
            $this->warn('DRY RUN - no changes made');
        }

        return Command::SUCCESS;
    }

    private function findTenantIdForLink(object $link): ?int
    {
        // Try to get tenant from related journal entry
        if ($link->journal_entry_id) {
            $entry = DB::table('journal_entries')
                ->where('id', $link->journal_entry_id)
                ->first();

            if ($entry && isset($entry->tenant_id)) {
                return $entry->tenant_id;
            }
        }

        // Try to get tenant from original journal entry (for reversal links)
        if ($link->original_journal_entry_id) {
            $entry = DB::table('journal_entries')
                ->where('id', $link->original_journal_entry_id)
                ->first();

            if ($entry && isset($entry->tenant_id)) {
                return $entry->tenant_id;
            }
        }

        return null;
    }
}
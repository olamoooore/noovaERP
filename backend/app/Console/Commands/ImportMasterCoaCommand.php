<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import a standard chart-of-accounts structure into master_coa_nodes
 * and create entity_account_mappings for all chart_of_accounts rows.
 *
 * Usage:
 *   php artisan accounting:import-master-coa
 *   php artisan accounting:import-master-coa --tenant=asct
 */
class ImportMasterCoaCommand extends Command
{
    protected $signature = 'accounting:import-master-coa
                            {--tenant= : Specific tenant ID (required)}
                            {--dry-run : Preview without writing}';

    protected $description = 'Import standard COA nodes and map existing entity accounts';

    /**
     * Standard master COA skeleton — one entry per natural account group.
     * code, name, type, parent_code (null = root), level
     */
    private const MASTER_NODES = [
        // Assets
        ['1000', 'Cash & Cash Equivalents', 'asset', null, 1],
        ['1100', 'Accounts Receivable', 'asset', null, 1],
        ['1200', 'Inventory', 'asset', null, 1],
        ['1300', 'Fixed Assets', 'asset', null, 1],
        ['1500', 'Accumulated Depreciation', 'asset', null, 1],
        ['1900', 'Other Assets', 'asset', null, 1],
        // Liabilities
        ['2000', 'Accounts Payable', 'liability', null, 1],
        ['2100', 'Short-term Liabilities', 'liability', null, 1],
        ['2200', 'Long-term Liabilities', 'liability', null, 1],
        // Equity
        ['3000', 'Owner Capital', 'equity', null, 1],
        ['3100', 'Retained Earnings', 'equity', null, 1],
        ['3200', 'Drawings', 'equity', null, 1],
        // Revenue
        ['4000', 'Sales Revenue', 'revenue', null, 1],
        ['4100', 'Other Income', 'revenue', null, 1],
        // Expenses
        ['5000', 'Cost of Goods Sold', 'expense', null, 1],
        ['5100', 'Operating Expenses', 'expense', null, 1],
        ['5200', 'Other Expenses', 'expense', null, 1],
        ['6000', 'Depreciation Expense', 'expense', null, 1],
        ['7000', 'Gains & Losses', 'expense', null, 1],
    ];

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        if (!$tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan accounting:import-master-coa --tenant=<tenant-id>');
            return self::FAILURE;
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return self::FAILURE;
        }

        $this->info("Processing tenant: {$tenant->id}");

        return $tenant->run(function () {
            return $this->processTenantCoa();
        });
    }

    /**
     * Process COA for a tenant.
     */
    protected function processTenantCoa(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('[DRY RUN] No data will be written.');
        }

        // 1. Seed master_coa_nodes
        $this->info('Seeding master_coa_nodes...');
        $nodeMap = []; // code → id

        foreach (self::MASTER_NODES as [$code, $name, $type, $parentCode, $level]) {
            $existing = DB::table('master_coa_nodes')->where('code', $code)->first();
            if ($existing) {
                $nodeMap[$code] = $existing->id;
                $this->line("  [exists] {$code} — {$name}");
                continue;
            }

            if ($dryRun) {
                $this->line("  [would create] {$code} — {$name}");
                continue;
            }

            $parentId = $parentCode ? ($nodeMap[$parentCode] ?? null) : null;
            $id = DB::table('master_coa_nodes')->insertGetId([
                'code'       => $code,
                'name'       => $name,
                'type'       => $type,
                'parent_id'  => $parentId,
                'level'      => $level,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $nodeMap[$code] = $id;
            $this->line("  [created] {$code} — {$name} (id={$id})");
        }

        // 2. Map entity chart_of_accounts → master_coa_nodes by closest code prefix
        $this->info('Mapping entity accounts to master nodes...');
        $entityAccounts = DB::table('chart_of_accounts')->get();
        $masterCodes = $dryRun
            ? array_map(fn($n) => $n[0], self::MASTER_NODES)
            : array_keys($nodeMap);

        sort($masterCodes);
        $mapped = 0;
        $skipped = 0;

        foreach ($entityAccounts as $account) {
            // already mapped?
            $exists = DB::table('entity_account_mappings')
                ->where('chart_of_account_id', $account->id)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            // find best matching master node by longest code prefix
            $bestMatch = $this->findBestMasterCode($account->code, $masterCodes);

            if (!$bestMatch) {
                $this->warn("  [no match] {$account->code} — {$account->name}");
                continue;
            }

            if ($dryRun) {
                $this->line("  [would map] {$account->code} → master {$bestMatch}");
                $mapped++;
                continue;
            }

            $masterNodeId = $nodeMap[$bestMatch] ?? null;
            if (!$masterNodeId) {
                continue;
            }

            DB::table('entity_account_mappings')->insert([
                'chart_of_account_id' => $account->id,
                'master_coa_node_id'  => $masterNodeId,
                'mapping_type'        => 'auto',
                'confidence'          => 0.8,
                'is_manual_override'  => false,
                'version'             => 1,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
            $mapped++;
        }

        $this->info("Mapped {$mapped} accounts, skipped {$skipped} (already mapped).");

        return self::SUCCESS;
    }

    /**
     * Find the master code whose prefix best matches the entity account code.
     * E.g. entity code "1050" matches master "1000" (first digit group).
     */
    private function findBestMasterCode(string $entityCode, array $masterCodes): ?string
    {
        $best = null;
        $bestLen = 0;

        foreach ($masterCodes as $mc) {
            // Use first N digits of entity code to match
            $prefixLen = strlen($mc);
            $entityPrefix = substr($entityCode, 0, min(2, strlen($entityCode))); // match first 2 digits
            $masterPrefix = substr($mc, 0, 2);

            if ($entityPrefix === $masterPrefix) {
                // Prefer the master code with the longest matching prefix
                $fullMatch = 0;
                for ($i = 0; $i < min(strlen($entityCode), strlen($mc)); $i++) {
                    if ($entityCode[$i] === $mc[$i]) {
                        $fullMatch++;
                    } else {
                        break;
                    }
                }
                if ($fullMatch > $bestLen) {
                    $bestLen = $fullMatch;
                    $best = $mc;
                }
            }
        }

        return $best;
    }
}

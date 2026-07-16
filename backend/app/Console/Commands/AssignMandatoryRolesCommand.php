<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auto-assign mandatory account roles to existing tenant COA accounts
 * by matching known code patterns to role codes.
 *
 * Usage:
 *   php artisan accounting:assign-roles
 *   php artisan accounting:assign-roles --dry-run
 *   php artisan accounting:assign-roles --tenant=asct
 */
class AssignMandatoryRolesCommand extends Command
{
    protected $signature = 'accounting:assign-roles
                            {--tenant= : Specific tenant ID (required)}
                            {--dry-run : Preview without writing}
                            {--force : Skip confirmation for ambiguous assignments}';

    protected $description = 'Auto-assign mandatory account roles to existing tenant accounts';

    /**
     * Maps account_roles.code → array of COA code patterns to match.
     * First match wins (order matters).
     */
    private const ROLE_PATTERNS = [
        'CASH_MAIN' => ['1010', '1000'],
        'BANK_MAIN' => ['1020', '1001'],
        'AR_CONTROL' => ['1100', '1110'],
        'AP_CONTROL' => ['2100', '2000'],
        'INVENTORY' => ['1200', '1210'],
        'COGS' => ['5100', '5000'],
        'TAX_INPUT' => ['2200', '2210'],
        'TAX_OUTPUT' => ['2200', '2220'],
        'RETAINED_EARNINGS' => ['3100', '3900'],
        'FX_GAIN' => ['7200'],
        'FX_LOSS' => ['7201'],
        'SUSPENSE' => ['9000', '9999'],
        'ACCRUED_EXPENSES' => ['2300', '2310'],
        'PREPAID_EXPENSES' => ['1400', '1410'],
        'OUTSTANDING_RECEIPTS' => ['1015'],
        'OUTSTANDING_PAYMENTS' => ['1025'],
        'INTERCOMPANY_DUE_FROM' => ['1800'],
        'INTERCOMPANY_DUE_TO' => ['2800'],
    ];

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        if (! $tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan accounting:assign-roles --tenant=<tenant-id>');

            return self::FAILURE;
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant not found: {$tenantId}");

            return self::FAILURE;
        }

        $this->info("Processing tenant: {$tenant->id}");

        $tenant->run(function () {
            $this->processTenantRoles();
        });

        return self::SUCCESS;
    }

    /**
     * Process roles for a tenant.
     */
    protected function processTenantRoles(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if ($dryRun) {
            $this->info('[DRY RUN] No data will be written.');
        }

        // Load all mandatory roles
        $roles = DB::table('account_roles')
            ->where('is_mandatory', true)
            ->get()
            ->keyBy('code');

        if ($roles->isEmpty()) {
            $this->error('No mandatory account roles found. Run tenant migrations first.');

            return self::FAILURE;
        }

        $this->info("Found {$roles->count()} mandatory roles.");

        // Load all entity COA accounts
        $accounts = DB::table('chart_of_accounts')
            ->where('is_active', true)
            ->get()
            ->keyBy('code');

        $assigned = 0;
        $skipped = 0;
        $ambiguous = [];

        foreach (self::ROLE_PATTERNS as $roleCode => $patterns) {
            $role = $roles->get($roleCode);
            if (! $role) {
                $this->warn("  Role '{$roleCode}' not found in account_roles table, skipping.");

                continue;
            }

            // Check if already assigned
            $existing = DB::table('entity_account_roles')
                ->where('account_role_id', $role->id)
                ->where('is_active', true)
                ->first();

            if ($existing) {
                $skipped++;

                continue;
            }

            // Find matching account
            $matchedAccount = null;
            foreach ($patterns as $codePattern) {
                if ($accounts->has($codePattern)) {
                    $matchedAccount = $accounts->get($codePattern);
                    break;
                }
            }

            if (! $matchedAccount) {
                $ambiguous[] = $roleCode;
                $this->warn("  [{$roleCode}] No matching account for patterns: ".implode(', ', $patterns));

                continue;
            }

            if (! $force && ! $dryRun) {
                if (! $this->confirm("Assign {$roleCode} → {$matchedAccount->code} ({$matchedAccount->name})?", true)) {
                    $this->line("  Skipped {$roleCode}.");

                    continue;
                }
            }

            if ($dryRun) {
                $this->line("  [would assign] {$roleCode} → {$matchedAccount->code} ({$matchedAccount->name})");
                $assigned++;

                continue;
            }

            DB::table('entity_account_roles')->insert([
                'account_role_id' => $role->id,
                'chart_of_account_id' => $matchedAccount->id,
                'is_active' => true,
                'assigned_by' => 'system:auto-assign',
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info("  [assigned] {$roleCode} → {$matchedAccount->code} ({$matchedAccount->name})");
            $assigned++;
        }

        $this->newLine();
        $this->info("Assigned: {$assigned}, Skipped (existing): {$skipped}");
        if (! empty($ambiguous)) {
            $this->warn('Unresolved roles requiring manual assignment: '.implode(', ', $ambiguous));
        }

        return self::SUCCESS;
    }
}

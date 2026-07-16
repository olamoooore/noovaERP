<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\SuperAdmin\Application\EntitlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild tenant entitlements to include HRM module.
 *
 * This command:
 * 1. Verifies HRM exists in catalog_items
 * 2. Identifies all tenants
 * 3. Rebuilds entitlements for each tenant
 * 4. Clears feature gate cache
 *
 * Usage: php artisan hrm:rebuild-entitlements
 */
class RebuildHrmEntitlementsCommand extends Command
{
    protected $signature = 'hrm:rebuild-entitlements
                            {--tenant= : Specific tenant ID to rebuild (optional)}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Rebuild tenant entitlements to include HRM module';

    public function __construct(private readonly EntitlementService $entitlementService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting HRM entitlement rebuild...');

        // Step 1: Verify HRM exists in catalog_items
        $this->info('Step 1: Verifying HRM exists in catalog_items...');
        $hrmCatalogItem = $this->verifyHrmInCatalog();

        if (! $hrmCatalogItem) {
            $this->error('HRM not found in catalog_items. Please run ModuleCatalogSyncService::sync() first.');

            return self::FAILURE;
        }

        $this->info("✓ HRM found in catalog_items (ID: {$hrmCatalogItem->id}, Key: {$hrmCatalogItem->key})");

        // Step 2: Identify tenants to rebuild
        $this->info('Step 2: Identifying tenants...');
        $tenants = $this->identifyTenants();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found to rebuild.');

            return self::SUCCESS;
        }

        $this->info("Found {$tenants->count()} tenant(s) to rebuild.");

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->table(
                ['Tenant ID', 'Name', 'Email'],
                $tenants->map(fn ($t) => [$t->id, $t->name, $t->email])->toArray()
            );

            return self::SUCCESS;
        }

        // Step 3: Rebuild entitlements for each tenant
        $this->info('Step 3: Rebuilding entitlements...');
        $successCount = 0;
        $failureCount = 0;

        $progressBar = $this->output->createProgressBar($tenants->count());
        $progressBar->start();

        foreach ($tenants as $tenant) {
            try {
                // Rebuild entitlements
                $job = $this->entitlementService->rebuild(
                    tenantId: $tenant->id,
                    adminId: null,
                    reason: 'hrm_module_fix'
                );

                // Clear feature gate cache
                Cache::forget("feature_gate:{$tenant->id}");

                $successCount++;
                $progressBar->advance();
            } catch (\Throwable $e) {
                $failureCount++;
                $this->newLine();
                $this->error("Failed to rebuild entitlements for tenant {$tenant->id}: {$e->getMessage()}");
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Step 4: Verify results
        $this->info('Step 4: Verifying results...');
        $this->verifyResults($tenants, $hrmCatalogItem);

        // Summary
        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Total tenants: {$tenants->count()}");
        $this->info("Successfully rebuilt: {$successCount}");
        if ($failureCount > 0) {
            $this->error("Failed: {$failureCount}");
        }

        return $failureCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function verifyHrmInCatalog(): ?object
    {
        return DB::connection('pgsql')->table('catalog_items')
            ->where('key', 'hrm')
            ->where('is_active', true)
            ->first();
    }

    private function identifyTenants()
    {
        if ($tenantId = $this->option('tenant')) {
            return Tenant::where('id', $tenantId)->get();
        }

        // Get all tenants
        return Tenant::all();
    }

    private function verifyResults($tenants, $hrmCatalogItem): void
    {
        $entitledCount = 0;
        $notEntitledCount = 0;
        $missingCount = 0;

        foreach ($tenants as $tenant) {
            $entitlement = DB::connection('pgsql')->table('tenant_entitlements')
                ->where('tenant_id', $tenant->id)
                ->where('catalog_item_id', $hrmCatalogItem->id)
                ->first();

            if (! $entitlement) {
                $missingCount++;
                $this->warn("  ⚠ Tenant {$tenant->id}: No entitlement record found");
            } elseif ($entitlement->is_entitled) {
                $entitledCount++;
                $this->info("  ✓ Tenant {$tenant->id}: Entitled (source: {$entitlement->source})");
            } else {
                $notEntitledCount++;
                $this->comment("  - Tenant {$tenant->id}: Not entitled (source: {$entitlement->source})");
            }
        }

        $this->newLine();
        $this->info("Entitled: {$entitledCount}");
        $this->info("Not entitled: {$notEntitledCount}");
        if ($missingCount > 0) {
            $this->warn("Missing entitlement records: {$missingCount}");
        }
    }
}

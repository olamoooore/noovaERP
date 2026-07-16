<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\SuperAdmin\Application\EntitlementService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\PlanFamily;
use App\SuperAdmin\Domain\PlanItemDefault;
use App\SuperAdmin\Domain\PlanVersion;
use App\SuperAdmin\Domain\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fix entitlements for tenants that were created without subscriptions.
 *
 * This command:
 * 1. Ensures trial plan family/version exists
 * 2. Populates plan_item_defaults for trial (all active catalog items)
 * 3. Creates trial subscriptions for tenants missing one
 * 4. Rebuilds entitlements
 *
 * Usage: php artisan tenants:fix-entitlements
 */
class FixTenantEntitlementsCommand extends Command
{
    protected $signature = 'tenants:fix-entitlements
                            {--tenant= : Specific tenant ID to fix (optional)}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Fix entitlements for tenants created without subscriptions';

    public function __construct(private readonly EntitlementService $entitlementService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting tenant entitlement fix...');

        DB::statement('SET search_path TO public');

        // Step 1: Ensure trial plan family exists
        $this->info('Step 1: Ensuring trial plan family exists...');
        $trialFamily = PlanFamily::firstOrCreate(
            ['key' => 'trial'],
            [
                'name' => 'Trial',
                'description' => 'Free trial — full access for 14 days.',
                'is_active' => true,
                'sort_order' => 0,
            ]
        );
        $this->info("✓ Trial plan family: {$trialFamily->name} (ID: {$trialFamily->id})");

        // Step 2: Ensure trial plan version exists
        $this->info('Step 2: Ensuring trial plan version exists...');
        $trialVersion = PlanVersion::firstOrCreate(
            ['plan_family_id' => $trialFamily->id, 'version_label' => 'v1'],
            [
                'is_active' => true,
                'is_publicly_listed' => true,
                'description' => 'Trial version',
            ]
        );
        $this->info("✓ Trial plan version: {$trialVersion->version_label} (ID: {$trialVersion->id})");

        // Step 3: Ensure plan_item_defaults exist for trial
        $this->info('Step 3: Populating plan_item_defaults for trial plan...');
        $activeItems = CatalogItem::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            })
            ->get();

        $this->info("Found {$activeItems->count()} active catalog items");

        $now = now();
        $newDefaults = 0;
        foreach ($activeItems as $item) {
            $created = PlanItemDefault::firstOrCreate(
                [
                    'plan_family_id' => $trialFamily->id,
                    'catalog_item_id' => $item->id,
                ],
                [
                    'availability' => PlanItemDefault::AVAILABILITY_INCLUDED,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            if ($created->wasRecentlyCreated) {
                $newDefaults++;
            }
        }
        $this->info("✓ {$newDefaults} new plan_item_defaults created ({$activeItems->count()} total)");

        // Step 4: Identify tenants to fix
        $this->info('Step 4: Identifying tenants...');
        $tenants = $this->identifyTenants();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants need fixing.');

            return self::SUCCESS;
        }

        $this->info("Found {$tenants->count()} tenant(s) to fix.");

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->table(
                ['Tenant ID', 'Name', 'Email', 'Has Subscription'],
                $tenants->map(fn ($t) => [
                    $t->id,
                    $t->name,
                    $t->email,
                    $t->has_subscription ? 'Yes' : 'No',
                ])->toArray()
            );

            return self::SUCCESS;
        }

        // Step 5: Create subscriptions and rebuild entitlements
        $this->info('Step 5: Creating subscriptions and rebuilding entitlements...');
        $successCount = 0;
        $failureCount = 0;

        $progressBar = $this->output->createProgressBar($tenants->count());
        $progressBar->start();

        foreach ($tenants as $tenant) {
            try {
                // Create trial subscription if missing
                if (! $tenant->has_subscription) {
                    Subscription::create([
                        'tenant_id' => $tenant->id,
                        'plan_version_id' => $trialVersion->id,
                        'status' => 'trialing',
                        'trial_starts_at' => $tenant->created_at ?? now(),
                        'trial_ends_at' => ($tenant->created_at ?? now())->copy()->addDays(14),
                        'current_period_start' => $tenant->created_at ?? now(),
                        'current_period_end' => ($tenant->created_at ?? now())->copy()->addDays(14),
                    ]);
                }

                // Rebuild entitlements
                $this->entitlementService->rebuild(
                    tenantId: $tenant->id,
                    adminId: null,
                    reason: 'tenant_entitlement_fix'
                );

                $successCount++;
                $progressBar->advance();
            } catch (\Throwable $e) {
                $failureCount++;
                $this->newLine();
                $this->error("Failed to fix tenant {$tenant->id} ({$tenant->name}): {$e->getMessage()}");
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('=== Summary ===');
        $this->info("Total tenants processed: {$tenants->count()}");
        $this->info("Successfully fixed: {$successCount}");
        if ($failureCount > 0) {
            $this->error("Failed: {$failureCount}");
        }

        return $failureCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function identifyTenants()
    {
        if ($tenantId = $this->option('tenant')) {
            return Tenant::where('id', $tenantId)->get();
        }

        // Get all tenants that don't have a subscription
        $tenantsWithSubscriptions = DB::table('subscriptions')
            ->pluck('tenant_id')
            ->toArray();

        return Tenant::whereNotIn('id', $tenantsWithSubscriptions)->get();
    }
}

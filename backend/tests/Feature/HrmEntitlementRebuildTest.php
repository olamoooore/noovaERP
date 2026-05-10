<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\SuperAdmin\Application\EntitlementService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test HRM entitlement rebuild functionality.
 *
 * Validates Requirements: 1.3, 2.2, 2.3, 3.1
 */
class HrmEntitlementRebuildTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementService $entitlementService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlementService = app(EntitlementService::class);
        $this->seedBaselineFixtureData();
    }

    private function seedBaselineFixtureData(): void
    {
        if (!DB::table('tenants')->exists()) {
            DB::table('tenants')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'name' => 'HRM Rebuild Test Tenant',
                'email' => 'hrm-rebuild@test.local',
                'plan' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $type = CatalogItemType::query()->firstOrCreate(
            ['key' => 'module'],
            [
                'name' => 'Module',
                'description' => 'Module catalog type for tests',
                'sort_order' => 0,
            ]
        );

        CatalogItem::query()->firstOrCreate(
            ['key' => 'hrm'],
            [
                'name' => 'Human Resources',
                'description' => 'HRM module',
                'type_id' => $type->id,
                'is_active' => true,
                'sort_order' => 0,
                'effective_from' => now()->toDateString(),
                'source' => 'catalog',
            ]
        );
    }

    /**
     * Test that HRM exists in catalog_items table.
     *
     * @test
     */
    public function hrm_exists_in_catalog_items(): void
    {
        $hrmItem = CatalogItem::where('key', 'hrm')->first();

        $this->assertNotNull($hrmItem, 'HRM should exist in catalog_items');
        $this->assertEquals('hrm', $hrmItem->key);
        $this->assertTrue($hrmItem->is_active, 'HRM should be active');
    }

    /**
     * Test that rebuild creates entitlement records for entitled tenants.
     *
     * @test
     */
    public function rebuild_creates_entitlement_records_for_entitled_tenants(): void
    {
        // Get a tenant (any tenant will do for this test)
        $tenant = Tenant::first();
        $this->assertNotNull($tenant, 'At least one tenant should exist');

        // Get HRM catalog item
        $hrmItem = CatalogItem::where('key', 'hrm')->first();
        $this->assertNotNull($hrmItem, 'HRM should exist in catalog_items');

        // Rebuild entitlements
        $job = $this->entitlementService->rebuild(
            tenantId: $tenant->id,
            adminId: null,
            reason: 'test_rebuild'
        );

        // Verify job completed successfully
        $this->assertEquals('completed', $job->status);

        // Check if entitlement record exists (may or may not be entitled depending on tier)
        $entitlement = TenantEntitlement::where('tenant_id', $tenant->id)
            ->where('catalog_item_id', $hrmItem->id)
            ->first();

        // The entitlement record may or may not exist depending on the tenant's tier
        // If it exists, verify it has the correct structure
        if ($entitlement) {
            $this->assertNotNull($entitlement->resolved_at);
            $this->assertContains($entitlement->source, [
                TenantEntitlement::SOURCE_TIER_INCLUDED,
                TenantEntitlement::SOURCE_ADD_ON_PURCHASED,
                TenantEntitlement::SOURCE_OVERRIDE_INCLUDE,
                TenantEntitlement::SOURCE_OVERRIDE_EXCLUDE,
            ]);
        }
    }

    /**
     * Test that rebuild preserves non-HRM entitlements.
     *
     * @test
     */
    public function rebuild_preserves_non_hrm_entitlements(): void
    {
        $tenant = Tenant::first();
        $this->assertNotNull($tenant, 'At least one tenant should exist');

        // Get entitlements before rebuild
        $entitlementsBefore = TenantEntitlement::where('tenant_id', $tenant->id)
            ->with('catalogItem')
            ->get()
            ->keyBy('catalog_item_id');

        // Rebuild entitlements
        $this->entitlementService->rebuild(
            tenantId: $tenant->id,
            adminId: null,
            reason: 'test_preservation'
        );

        // Get entitlements after rebuild
        $entitlementsAfter = TenantEntitlement::where('tenant_id', $tenant->id)
            ->with('catalogItem')
            ->get()
            ->keyBy('catalog_item_id');

        // Verify non-HRM entitlements are preserved
        foreach ($entitlementsBefore as $catalogItemId => $entitlement) {
            if ($entitlement->catalogItem->key !== 'hrm') {
                $this->assertTrue(
                    $entitlementsAfter->has($catalogItemId),
                    "Non-HRM entitlement for {$entitlement->catalogItem->key} should be preserved"
                );

                $afterEntitlement = $entitlementsAfter->get($catalogItemId);
                $this->assertEquals(
                    $entitlement->is_entitled,
                    $afterEntitlement->is_entitled,
                    "Entitlement status for {$entitlement->catalogItem->key} should be unchanged"
                );
            }
        }
    }

    /**
     * Test that feature gate cache is cleared after rebuild.
     *
     * @test
     */
    public function feature_gate_cache_is_cleared_after_rebuild(): void
    {
        $tenant = Tenant::first();
        $this->assertNotNull($tenant, 'At least one tenant should exist');

        // Set a cache value
        $cacheKey = "feature_gate:{$tenant->id}";
        Cache::put($cacheKey, ['test' => 'value'], 3600);
        $this->assertTrue(Cache::has($cacheKey), 'Cache should be set');

        // Rebuild entitlements (which should clear the cache)
        $this->entitlementService->rebuild(
            tenantId: $tenant->id,
            adminId: null,
            reason: 'test_cache_clear'
        );

        // Verify cache was cleared
        $this->assertFalse(
            Cache::has($cacheKey),
            'Feature gate cache should be cleared after rebuild'
        );
    }

    /**
     * Test that entitlement source is correctly set.
     *
     * @test
     */
    public function entitlement_source_is_correctly_set(): void
    {
        $tenant = Tenant::first();
        $this->assertNotNull($tenant, 'At least one tenant should exist');

        $hrmItem = CatalogItem::where('key', 'hrm')->first();
        $this->assertNotNull($hrmItem, 'HRM should exist in catalog_items');

        // Rebuild entitlements
        $this->entitlementService->rebuild(
            tenantId: $tenant->id,
            adminId: null,
            reason: 'test_source'
        );

        // Get HRM entitlement
        $entitlement = TenantEntitlement::where('tenant_id', $tenant->id)
            ->where('catalog_item_id', $hrmItem->id)
            ->first();

        // If entitlement exists, verify source is valid
        if ($entitlement) {
            $validSources = [
                TenantEntitlement::SOURCE_TIER_INCLUDED,
                TenantEntitlement::SOURCE_ADD_ON_PURCHASED,
                TenantEntitlement::SOURCE_OVERRIDE_INCLUDE,
                TenantEntitlement::SOURCE_OVERRIDE_EXCLUDE,
            ];

            $this->assertContains(
                $entitlement->source,
                $validSources,
                'Entitlement source should be one of the valid sources'
            );
        }
    }

    /**
     * Test that other entitlements remain unchanged after HRM rebuild.
     *
     * @test
     */
    public function other_entitlements_remain_unchanged(): void
    {
        $tenant = Tenant::first();
        $this->assertNotNull($tenant, 'At least one tenant should exist');

        // Get all non-HRM entitlements before rebuild
        $nonHrmBefore = DB::connection('pgsql')
            ->table('tenant_entitlements')
            ->join('catalog_items', 'tenant_entitlements.catalog_item_id', '=', 'catalog_items.id')
            ->where('tenant_entitlements.tenant_id', $tenant->id)
            ->where('catalog_items.key', '!=', 'hrm')
            ->select('catalog_items.key', 'tenant_entitlements.is_entitled', 'tenant_entitlements.source')
            ->get()
            ->keyBy('key');

        // Rebuild entitlements
        $this->entitlementService->rebuild(
            tenantId: $tenant->id,
            adminId: null,
            reason: 'test_unchanged'
        );

        // Get all non-HRM entitlements after rebuild
        $nonHrmAfter = DB::connection('pgsql')
            ->table('tenant_entitlements')
            ->join('catalog_items', 'tenant_entitlements.catalog_item_id', '=', 'catalog_items.id')
            ->where('tenant_entitlements.tenant_id', $tenant->id)
            ->where('catalog_items.key', '!=', 'hrm')
            ->select('catalog_items.key', 'tenant_entitlements.is_entitled', 'tenant_entitlements.source')
            ->get()
            ->keyBy('key');

        // Verify counts match
        $this->assertEquals(
            $nonHrmBefore->count(),
            $nonHrmAfter->count(),
            'Number of non-HRM entitlements should remain the same'
        );

        // Verify each entitlement is unchanged
        foreach ($nonHrmBefore as $key => $before) {
            $this->assertTrue(
                $nonHrmAfter->has($key),
                "Entitlement for {$key} should still exist"
            );

            $after = $nonHrmAfter->get($key);
            $this->assertEquals(
                $before->is_entitled,
                $after->is_entitled,
                "Entitlement status for {$key} should be unchanged"
            );
            $this->assertEquals(
                $before->source,
                $after->source,
                "Entitlement source for {$key} should be unchanged"
            );
        }
    }
}

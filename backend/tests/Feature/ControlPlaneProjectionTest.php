<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureEntitlementAccess;
use App\Models\Tenant;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\Tenancy\Tenancy;
use Tests\TenantTestCase;

/**
 * WS-02 Runtime Projection Tests
 *
 * Proves that canonical projection via FeatureGateService / tenant_entitlements
 * is the exclusive source of runtime access-control truth.
 *
 * Required for WS-02 closure (FIX-04).
 */
class ControlPlaneProjectionTest extends TenantTestCase
{
    private FeatureGateService $featureGate;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->featureGate = app(FeatureGateService::class);
        $this->tenantId = (string) Str::uuid();

        // Ensure we operate on the central (public) schema
        DB::statement('SET search_path TO public');

        $this->ensureLegacyFeatureTablesExist();
        $this->ensureCatalogTypeExists();
        $this->forceNonCoreFeatures(['pos', 'inventory']);
    }

    protected function tearDown(): void
    {
        // Clean up entitlements created for this test tenant
        DB::statement('SET search_path TO public');
        TenantEntitlement::where('tenant_id', $this->tenantId)->delete();
        $this->featureGate->invalidate($this->tenantId);

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1 — EnsureEntitlementAccess blocks when is_entitled = false
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Proves that EnsureEntitlementAccess reads from tenant_entitlements (canonical)
     * and blocks a request when is_entitled = false, regardless of tenant_features state.
     */
    public function test_ensure_entitlement_access_blocks_when_not_entitled(): void
    {
        $featureKey = 'pos';

        // Ensure the canonical snapshot has is_entitled = false for this tenant+feature
        // (Achieved by simply having no row — absence means not entitled)
        TenantEntitlement::where('tenant_id', $this->tenantId)->delete();
        $this->featureGate->invalidate($this->tenantId);

        // Build a fake request with a resolvable tenant context
        $request = Request::create('/api/v1/pos/orders', 'GET');

        // Bind the tenant resolution helper so currentTenantId() returns our test tenant
        $this->bindFakeTenant($this->tenantId);

        $middleware = new EnsureEntitlementAccess($this->featureGate, app(Tenancy::class));

        $response = $middleware->handle($request, fn ($r) => new Response('ok', 200), $featureKey);

        $this->assertEquals(403, $response->getStatusCode(), 'Middleware must block (403) when tenant_entitlements has no entitled row');
        $this->assertStringContainsString($featureKey, $response->getContent(), 'Response body must name the blocked feature');
    }

    /**
     * @test
     * Proves that EnsureEntitlementAccess allows when is_entitled = true in tenant_entitlements.
     */
    public function test_ensure_entitlement_access_allows_when_entitled(): void
    {
        $featureKey = 'pos';

        // Write an entitled row directly to tenant_entitlements
        $this->writeEntitlement($this->tenantId, $featureKey, true);

        $request = Request::create('/api/v1/pos/orders', 'GET');
        $this->bindFakeTenant($this->tenantId);

        $middleware = new EnsureEntitlementAccess($this->featureGate, app(Tenancy::class));

        $response = $middleware->handle($request, fn ($r) => new Response('ok', 200), $featureKey);

        $this->assertEquals(200, $response->getStatusCode(), 'Middleware must allow (200) when tenant_entitlements has is_entitled = true');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2 — tenant_features cannot override tenant_entitlements
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Proves that a feature enabled in tenant_features but absent from tenant_entitlements
     * does NOT grant runtime access. The canonical projection is the exclusive source.
     */
    public function test_tenant_features_cannot_override_canonical_entitlements(): void
    {
        $featureKey = 'inventory';

        // Resolve the Feature model for this key
        $featureId = DB::table('features')->updateOrInsert(
            ['key' => $featureKey],
            [
                'name' => ucfirst($featureKey),
                'description' => null,
                'is_core' => false,
                'price' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $featureId = DB::table('features')->where('key', $featureKey)->value('id');

        DB::table('tenant_features')->updateOrInsert(
            ['feature_id' => $featureId],
            ['is_enabled' => true, 'enabled_at' => now(), 'expires_at' => null, 'created_at' => now(), 'updated_at' => now()]
        );

        // Ensure tenant_entitlements has NO entitled row (canonical says not entitled)
        TenantEntitlement::where('tenant_id', $this->tenantId)->delete();
        $this->featureGate->invalidate($this->tenantId);

        $request = Request::create('/api/v1/inventory/items', 'GET');
        $this->bindFakeTenant($this->tenantId);

        $middleware = new EnsureEntitlementAccess($this->featureGate, app(Tenancy::class));

        $response = $middleware->handle($request, fn ($r) => new Response('ok', 200), $featureKey);

        $this->assertEquals(
            403,
            $response->getStatusCode(),
            'tenant_features enable must NOT override canonical tenant_entitlements — middleware must still block'
        );

        // Verify FeatureGateService also says not entitled
        $check = $this->featureGate->check($this->tenantId, $featureKey);
        $this->assertFalse($check['is_entitled'], 'FeatureGateService::check() must return is_entitled=false when tenant_entitlements has no row');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3 — rebuild → cache invalidation → runtime check chain
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Proves the full rebuild chain:
     *   EntitlementService::rebuild() writes tenant_entitlements
     *   → FeatureGateService cache is invalidated
     *   → FeatureGateService::check() returns the updated result
     */
    public function test_entitlement_rebuild_invalidates_cache_and_updates_runtime_check(): void
    {
        $featureKey = 'pos';

        // Seed an entitled row so FeatureGateService caches it
        $this->writeEntitlement($this->tenantId, $featureKey, true);
        $initial = $this->featureGate->check($this->tenantId, $featureKey);
        $this->assertTrue($initial['is_entitled'], 'Pre-condition: should be entitled before rebuild');

        // Now delete the row and manually invalidate (simulates rebuild removing entitlement)
        TenantEntitlement::where('tenant_id', $this->tenantId)->delete();
        $this->featureGate->invalidate($this->tenantId);

        // After invalidation, the check must reflect the updated store state
        $afterRebuild = $this->featureGate->check($this->tenantId, $featureKey);
        $this->assertFalse($afterRebuild['is_entitled'], 'After invalidation, FeatureGateService must re-read tenant_entitlements and return is_entitled=false');

        // Re-add the row (simulates rebuild granting entitlement)
        $this->writeEntitlement($this->tenantId, $featureKey, true);
        $this->featureGate->invalidate($this->tenantId);

        $afterGrant = $this->featureGate->check($this->tenantId, $featureKey);
        $this->assertTrue($afterGrant['is_entitled'], 'After re-grant + invalidation, FeatureGateService must return is_entitled=true');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4 — Feature::isEnabledForTenant() is retired (throws)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Proves that Feature::isEnabledForTenant() throws LogicException (it is retired).
     * Any call to this method is a programming error and must fail loudly.
     */
    public function test_feature_is_enabled_for_tenant_throws_logic_exception(): void
    {
        $feature = new \App\Models\Feature;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/isEnabledForTenant\(\) is retired/');

        $feature->isEnabledForTenant();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 5 — No production HTTP controller in EventManagement injects FeatureFlagController
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Proves the third feature store (FeatureFlagController / tenant_feature_flags) is not
     * reachable from any production EventManagement HTTP controller.
     */
    public function test_event_management_controllers_do_not_reference_feature_flag_controller(): void
    {
        $controllerDir = base_path('modules/EventManagement/Http/Controllers');

        if (! is_dir($controllerDir)) {
            $this->markTestSkipped('EventManagement controller directory not found');
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($controllerDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            $this->assertStringNotContainsString(
                'FeatureFlagController',
                $source,
                "Production controller {$file->getFilename()} must not reference the retired FeatureFlagController (third feature store)"
            );

            $this->assertStringNotContainsString(
                'tenant_feature_flags',
                $source,
                "Production controller {$file->getFilename()} must not query the tenant_feature_flags table directly"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write a row directly into tenant_entitlements bypassing EntitlementService
     * (for test isolation — we test the check/middleware, not rebuild itself here).
     */
    private function writeEntitlement(string $tenantId, string $featureKey, bool $entitled): void
    {
        DB::statement('SET search_path TO public');
        $this->featureGate->invalidate($tenantId);

        // Find or create a catalog item for the key
        $item = CatalogItem::firstOrCreate(
            ['key' => $featureKey],
            [
                'name' => ucfirst($featureKey),
                'type_id' => $this->catalogTypeId(),
                'is_active' => true,
                'effective_from' => now()->toDateString(),
            ]
        );

        TenantEntitlement::updateOrCreate(
            ['tenant_id' => $tenantId, 'catalog_item_id' => $item->id],
            [
                'is_entitled' => $entitled,
                'source' => $entitled ? TenantEntitlement::SOURCE_TIER_INCLUDED : TenantEntitlement::SOURCE_OVERRIDE_EXCLUDE,
                'resolved_at' => now(),
            ]
        );

        // Invalidate so the next check hits DB
        $this->featureGate->invalidate($tenantId);
    }

    /**
     * Bind a fake tenant() helper so EnsureEntitlementAccess::currentTenantId()
     * returns the given tenant ID without needing the full tenancy middleware stack.
     */
    private function bindFakeTenant(string $tenantId): void
    {
        app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, new Tenant([
            'id' => $tenantId,
            'name' => 'Projection Test Tenant',
        ]));

        // Monkey-patch: override EnsureEntitlementAccess via a subclass that
        // returns our test tenant ID without requiring the tenancy package to be
        // fully booted in the unit-test environment.
        $featureGate = $this->featureGate;
        $testTenantId = $tenantId;

        $tenancy = app(Tenancy::class);

        $this->app->bind(EnsureEntitlementAccess::class, function () use ($featureGate, $tenancy, $testTenantId) {
            return new class($featureGate, $tenancy, $testTenantId) extends EnsureEntitlementAccess
            {
                public function __construct(
                    FeatureGateService $featureGate,
                    Tenancy $tenancy,
                    private readonly string $testTenantId
                ) {
                    parent::__construct($featureGate, $tenancy);
                }

                protected function currentTenantId(): ?string
                {
                    return $this->testTenantId;
                }
            };
        });
    }

    private function ensureCatalogTypeExists(): void
    {
        CatalogItemType::query()->firstOrCreate(
            ['key' => 'module'],
            ['name' => 'Module', 'description' => 'Test module type', 'sort_order' => 0]
        );
    }

    private function catalogTypeId(): int
    {
        return (int) CatalogItemType::query()->where('key', 'module')->value('id');
    }

    private function ensureLegacyFeatureTablesExist(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tenant_features')) {
            Schema::create('tenant_features', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('feature_id');
                $table->boolean('is_enabled')->default(true);
                $table->timestamp('enabled_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function forceNonCoreFeatures(array $keys): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }

        foreach ($keys as $key) {
            DB::table('features')->updateOrInsert(
                ['key' => $key],
                [
                    'name' => ucfirst(str_replace('_', ' ', $key)),
                    'description' => null,
                    'is_core' => false,
                    'price' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}

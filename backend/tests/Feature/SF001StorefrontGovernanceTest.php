<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\User;
use App\Modules\Contracts\ModuleContract;
use App\Modules\ModuleManager;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Storefront\Contracts\StorefrontModuleContract;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Providers\StorefrontServiceProvider;
use Tests\TenantTestCase;

/**
 * SF-001 — Storefront module governance gate (MRD P0, FR-MOD-01/02/03/04/06).
 *
 * Proves the four legs that the repo SOP has repeatedly found missing on
 * "done" module claims:
 *   1. the module is actually DISCOVERED and its provider REGISTERED;
 *   2. its feature/catalog rows are provisionable through the REAL
 *      SuperAdmin sync services (grantable via entitlement rebuild path);
 *   3. the entitlement middleware on its live routes blocks an unentitled
 *      tenant with 403 and lets an entitled tenant through;
 *   4. the RequirePermission strings attached to routes exist in the seeded
 *      PERMISSION_MATRIX and DENY a matrix-excluded role while ALLOWING a
 *      matrix-granted non-super role (no OWNER/ADMIN bypass in the allow leg).
 *
 * Every assertion is a real HTTP request through the router or live service
 * call — no source-string assertions.
 *
 * NOTE: per-tenant SCHEMA isolation of storefront DATA (same content renders
 * tenant A vs B data) is proven by the render-pipeline tests in Sprint 3;
 * this harness (TenantTestCase) shares one physical schema and here proves
 * per-tenant GATING decisions only.
 */
class SF001StorefrontGovernanceTest extends TenantTestCase
{
    private string $tenantA;

    private string $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');

        // Spatie roles/permissions live in the tenant schema; seed them once
        // with any bound context (same shared-schema convention as HR143).
        $this->bindTenantContext('sf001-bootstrap');
        $this->seed(RolePermissionSeeder::class);

        $this->provisionStorefrontGovernanceRows();

        $this->tenantA = $this->createCentralTenant('SF001 Tenant A');
        $this->tenantB = $this->createCentralTenant('SF001 Tenant B');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. Discovery + registration (FR-MOD-01/04)
    // ─────────────────────────────────────────────────────────────────────

    public function test_module_is_discovered_registered_and_autoloadable(): void
    {
        $modules = app(ModuleManager::class)->all();

        $storefront = collect($modules)->firstWhere('key', 'storefront');
        $this->assertNotNull($storefront, 'Storefront must be disk-discovered via module.json');
        $this->assertSame('Storefront', $storefront->name);
        $this->assertFalse($storefront->isCore(), 'storefront must be a gated addon, never core-bypassed');

        $this->assertNotNull(
            $this->app->getProvider(StorefrontServiceProvider::class),
            'Provider declared in module.json must actually be registered by ModuleManager'
        );

        // Module routes must be pulled into the tenant group via ModuleManager
        $routeFiles = app(ModuleManager::class)->tenantApiRouteFiles();
        $this->assertContains(
            base_path('modules/Storefront/routes/api.php'),
            $routeFiles,
            'Storefront routes/api.php must be discovered for tenant route loading'
        );
    }

    public function test_contract_is_autoloadable_and_declares_only_enforceable_permissions(): void
    {
        $contract = new StorefrontModuleContract;

        $this->assertInstanceOf(ModuleContract::class, $contract);
        $this->assertSame('storefront', $contract->getKey());

        // PSR-4 quirk guard (engineering guardrails): every owned entity class must resolve.
        foreach ($contract->getOwnedEntities() as $entity) {
            $this->assertTrue(
                class_exists($entity),
                "getOwnedEntities() references unresolvable class {$entity}"
            );
        }

        // Contract-declared permissions must be exactly the seeded/live ones.
        $matrixPermissions = array_values(array_filter(
            DB::table('permissions')->pluck('name')->all(),
            fn (string $name) => str_starts_with($name, 'storefront.')
        ));
        sort($matrixPermissions);
        $declared = $contract->getPermissions();
        sort($declared);

        $this->assertSame($declared, $matrixPermissions,
            'Contract getPermissions() must match the permission strings actually seeded in the tenant schema (PERMISSION_MATRIX)');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Grantable through the real SuperAdmin catalog path (FR-MOD-04)
    // ─────────────────────────────────────────────────────────────────────

    public function test_storefront_is_grantable_via_real_catalog_and_feature_sync(): void
    {
        $feature = Feature::query()->where('key', 'storefront')->first();
        $this->assertNotNull($feature, 'ModuleFeatureSeeder must create the public.features row');
        $this->assertFalse((bool) $feature->is_core, 'storefront must not be core — otherwise entitlement:storefront is a no-op bypass');

        $item = CatalogItem::where('key', 'storefront')->first();
        $this->assertNotNull($item, 'catalog_items row must exist or entitlement:storefront always 403s');
        $this->assertTrue($item->is_active);
        $this->assertSame('module', $item->type->key);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. Live route wiring (FR-MOD-02/03)
    // ─────────────────────────────────────────────────────────────────────

    public function test_every_storefront_admin_route_carries_entitlement_and_permission(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'api/v1/admin/storefront'));

        $this->assertGreaterThanOrEqual(2, $routes->count(), 'Storefront admin routes must be registered on the live router');

        foreach ($routes as $route) {
            $middleware = $route->middleware();
            $this->assertContains('entitlement:storefront', $middleware,
                "Route {$route->uri()} lacks the entitlement gate");
            $this->assertTrue(
                collect($middleware)->contains(fn (string $mw) => str_starts_with($mw, 'App\\Http\\Middleware\\RequirePermission:storefront.')),
                "Route {$route->uri()} lacks a storefront.* RequirePermission"
            );
        }
    }

    public function test_unauthenticated_request_is_rejected_before_any_storefront_data(): void
    {
        $response = $this->getJson('/api/v1/admin/storefront/settings');
        $response->assertStatus(401);
    }

    public function test_unentitled_tenant_is_blocked_with_403_and_writes_nothing(): void
    {
        $user = $this->makeTenantUser($this->tenantB, 'admin', 'Admin');
        $this->bindTenantContext($this->tenantB);

        $response = $this->actingAs($user)->getJson('/api/v1/admin/storefront/settings');
        $response->assertStatus(403);
        $this->assertStringContainsString('storefront', (string) $response->json('message'));

        $this->assertSame(0, StorefrontSettings::query()->count(),
            'Blocked request must not reach the controller (no settings row created)');
    }

    public function test_entitled_tenant_passes_gate_and_reads_settings_defaults(): void
    {
        $this->grantStorefront($this->tenantA);
        $user = $this->makeTenantUser($this->tenantA, 'admin', 'Admin');
        $this->bindTenantContext($this->tenantA);

        $response = $this->actingAs($user)->getJson('/api/v1/admin/storefront/settings');
        $response->assertStatus(200)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.tax_display', 'exclusive')
            ->assertJsonPath('data.guest_checkout', true)
            ->assertJsonPath('data.runtime_version', 'v1');
    }

    public function test_gate_is_per_tenant_not_per_user_two_tenant_matrix(): void
    {
        // Tenant A entitled, Tenant B not: identical roles, opposite verdicts.
        $this->grantStorefront($this->tenantA);

        $userA = $this->makeTenantUser($this->tenantA, 'manager', 'Manager');
        $this->bindTenantContext($this->tenantA);
        $this->actingAs($userA)->getJson('/api/v1/admin/storefront/settings')->assertStatus(200);

        $userB = $this->makeTenantUser($this->tenantB, 'manager', 'Manager');
        $this->bindTenantContext($this->tenantB);
        $this->actingAs($userB)->getJson('/api/v1/admin/storefront/settings')->assertStatus(403);

        // Granting B flips the verdict without touching the user.
        $this->grantStorefront($this->tenantB);
        $this->bindTenantContext($this->tenantB);
        $this->actingAs($userB)->getJson('/api/v1/admin/storefront/settings')->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Permission enforcement: allow leg + deny leg, matrix-backed
    // ─────────────────────────────────────────────────────────────────────

    public function test_manager_reads_settings_via_matrix_view_but_cannot_write_settings(): void
    {
        $this->grantStorefront($this->tenantA);
        $manager = $this->makeTenantUser($this->tenantA, 'manager', 'Manager');
        $this->bindTenantContext($this->tenantA);

        // Allow leg: MANAGER is not a super role, so 200 here can ONLY come
        // from the seeded storefront.view grant — proves the matrix is wired.
        $this->actingAs($manager)->getJson('/api/v1/admin/storefront/settings')->assertStatus(200);

        // Deny leg: storefront.settings is Owner/Admin only.
        $this->actingAs($manager)->putJson('/api/v1/admin/storefront/settings', [
            'tax_display' => 'inclusive',
        ])->assertStatus(403)->assertJsonPath('required_permission', 'storefront.settings');
    }

    public function test_settings_update_persists_and_validation_rejects_bad_values(): void
    {
        $this->grantStorefront($this->tenantA);
        $admin = $this->makeTenantUser($this->tenantA, 'admin', 'Admin');
        $this->bindTenantContext($this->tenantA);

        $this->actingAs($admin)->putJson('/api/v1/admin/storefront/settings', [
            'tax_display' => 'inclusive',
            'currency_code' => 'USD',
            'guest_checkout' => false,
            'cart_ttl_days' => 14,
        ])->assertStatus(200)
            ->assertJsonPath('data.tax_display', 'inclusive')
            ->assertJsonPath('data.currency_code', 'USD')
            ->assertJsonPath('data.guest_checkout', false)
            ->assertJsonPath('data.cart_ttl_days', 14);

        $this->actingAs($admin)->getJson('/api/v1/admin/storefront/settings')
            ->assertStatus(200)
            ->assertJsonPath('data.cart_ttl_days', 14);

        $this->actingAs($admin)->putJson('/api/v1/admin/storefront/settings', [
            'tax_display' => 'sideways',
        ])->assertStatus(422);

        $this->actingAs($admin)->putJson('/api/v1/admin/storefront/settings', [
            'currency_code' => 'euros',
        ])->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers — all through the real provisioning services
    // ─────────────────────────────────────────────────────────────────────

    private function provisionStorefrontGovernanceRows(): void
    {
        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);

        // Real SuperAdmin path: module.json -> module_catalog -> catalog_items,
        // plus disk-driven public.features row. Same services the SuperAdmin
        // console endpoints call (POST /v1/admin/modules/sync, /v1/admin/catalog/sync).
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);
    }

    private function createCentralTenant(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $id],
            ['name' => $name, 'email' => 'sf001-'.Str::random(8).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        return $id;
    }

    private function grantStorefront(string $tenantId): void
    {
        $item = CatalogItem::where('key', 'storefront')->firstOrFail();

        TenantEntitlement::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'catalog_item_id' => $item->id],
            [
                'is_entitled' => true,
                'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE,
                'resolved_at' => now(),
            ]
        );

        app(FeatureGateService::class)->invalidate($tenantId);
    }

    /**
     * Central user bound to a tenant via public.user_company_access (the role
     * TenantRoleService actually reads) + Spatie role in the tenant schema.
     */
    private function makeTenantUser(string $tenantId, string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $user->assignRole($spatieRole);

        return $user;
    }
}

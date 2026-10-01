<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Application\TenantOperationService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\Tenancy;
use Tests\TenantTestCase;

/**
 * SF-031 — P5 / FR-PLAT-03 second half: suspended-tenant MAINTENANCE
 * response on the public surfaces.
 *
 * The Issue #212 guard (TenantSuspensionGuard on TenancyInitialized) already
 * fails every entry point with 503 JSON; this slice proves it over real HTTP
 * through the router for the storefront/websites a suspended tenant's
 * visitors actually hit, and upgrades the browser experience from a raw
 * JSON blob to a styled maintenance page (Retry-After on both).
 *
 * Proves, over real HTTP through the router:
 *   1. a suspended tenant's public storefront answers 503 with the
 *      TENANT_SUSPENDED contract + Retry-After (never shop data);
 *   2. browsers (Accept: text/html) get the styled maintenance page,
 *      not JSON and not a stack trace;
 *   3. website-public endpoints are covered by the same gate;
 *   4. reinstating restores serving;
 *   5. an UNVERIFIED custom host of a suspended tenant still 404s first
 *      (domain servability gates before suspension — no state leak);
 *   6. suspension is tenant-scoped — a sibling tenant keeps serving.
 */
class SF031MaintenanceResponseTest extends TenantTestCase
{
    private string $tenantA;

    private string $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');

        $this->bindTenantContext('sf031-bootstrap');
        $this->seed(RolePermissionSeeder::class);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantA = $this->createCentralTenant('SF031 Tenant A');
        $this->tenantB = $this->createCentralTenant('SF031 Tenant B');
        $this->grantStorefront($this->tenantA);
        $this->grantStorefront($this->tenantB);

        $this->platformDomain('sf31a.localhost', $this->tenantA);
        $this->platformDomain('sf31b.localhost', $this->tenantB);

        // See SF030: SetTenantSearchPath purges+reconnects the shared
        // connection on every real TenancyInitialized, which is fatal inside
        // RefreshDatabase's transaction (request runs on a fresh session that
        // cannot see this test's uncommitted rows). Search-path stays
        // 'public' here (TenantTestCase migrations run every schema into
        // public), so a no-op listener is behaviour-identical. The SUSPENSION
        // listener itself stays active — it is what this test exercises.
        $this->app->instance(\App\Listeners\SetTenantSearchPath::class, new class
        {
            public function handle($event): void {}
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1–2. The maintenance response itself (JSON contract + HTML page)
    // ─────────────────────────────────────────────────────────────────────

    public function test_suspended_tenant_public_storefront_returns_maintenance_503(): void
    {
        // Baseline: the shop serves while the tenant is active.
        $this->resetTenancyForPublicResolution();
        $this->getJson('http://sf31a.localhost/api/v1/storefront/catalog/products')
            ->assertStatus(200);

        app(TenantOperationService::class)->suspend($this->tenantA, 'SF031 maintenance response test');

        $this->resetTenancyForPublicResolution();
        $res = $this->getJson('http://sf31a.localhost/api/v1/storefront/catalog/products');
        $res->assertStatus(503)
            ->assertHeader('Retry-After', '3600')
            ->assertJsonPath('message', 'This workspace is suspended. Contact the platform administrator.')
            ->assertJsonPath('reason_code', 'TENANT_SUSPENDED');

        // The response must carry NO tenant data — no name, no id, no shop content.
        $body = (string) $res->getContent();
        $this->assertStringNotContainsString('SF031 Tenant A', $body);
        $this->assertStringNotContainsString($this->tenantA, $body);
    }

    public function test_browser_navigation_gets_the_styled_html_maintenance_page(): void
    {
        app(TenantOperationService::class)->suspend($this->tenantA, 'SF031 html maintenance page');

        $this->resetTenancyForPublicResolution();
        $res = $this->get(
            'http://sf31a.localhost/api/v1/storefront/catalog/products',
            ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']
        );

        $res->assertStatus(503)
            ->assertHeader('Retry-After', '3600');
        $this->assertStringContainsString(
            'text/html',
            strtolower((string) $res->headers->get('Content-Type')),
            'browser navigation must receive the HTML maintenance page'
        );

        $body = (string) $res->getContent();
        $this->assertStringContainsString('back shortly', $body);
        $this->assertStringContainsString('Maintenance', $body);
        $this->assertStringContainsString('noindex', $body);

        // Not a raw JSON error, not a stack trace, no tenant identity leaked.
        $this->assertStringNotContainsString('TENANT_SUSPENDED', $body);
        $this->assertStringNotContainsString('exception', strtolower($body));
        $this->assertStringNotContainsString('SF031 Tenant A', $body);
        $this->assertStringNotContainsString($this->tenantA, $body);
    }

    public function test_website_public_endpoints_are_covered_by_the_same_gate(): void
    {
        // website-public sits in the SAME middleware group (Initialize-
        // TenantForPublicAccess) but has no entitlement middleware — prove
        // the gate is the tenancy-initialized suspension listener, not a
        // storefront-specific one.
        $this->resetTenancyForPublicResolution();
        $this->getJson('http://sf31a.localhost/api/v1/website-public/products')
            ->assertStatus(200);

        app(TenantOperationService::class)->suspend($this->tenantA);

        $this->resetTenancyForPublicResolution();
        $this->getJson('http://sf31a.localhost/api/v1/website-public/products')
            ->assertStatus(503)
            ->assertJsonPath('reason_code', 'TENANT_SUSPENDED');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4–6. Lifecycle + ordering + isolation
    // ─────────────────────────────────────────────────────────────────────

    public function test_reinstating_the_tenant_restores_serving(): void
    {
        $svc = app(TenantOperationService::class);
        $svc->suspend($this->tenantA);

        $this->resetTenancyForPublicResolution();
        $this->getJson('http://sf31a.localhost/api/v1/storefront/catalog/products')
            ->assertStatus(503);

        $svc->reinstate($this->tenantA, 'SF031 resume');

        $this->resetTenancyForPublicResolution();
        $this->getJson('http://sf31a.localhost/api/v1/storefront/catalog/products')
            ->assertStatus(200);

        // Browser path too: no stale maintenance page after resume.
        $this->resetTenancyForPublicResolution();
        $this->get(
            'http://sf31a.localhost/api/v1/storefront/catalog/products',
            ['Accept' => 'text/html']
        )->assertStatus(200);
    }

    public function test_unverified_custom_host_of_suspended_tenant_still_fails_closed_404(): void
    {
        // Domain servability must gate BEFORE the suspension check: a pending
        // claim on a suspended tenant answers "not resolvable" (404), never
        // the maintenance page — otherwise the response would confirm that a
        // host maps to a suspended tenant the claimant does not yet control.
        DB::table('public.domains')->insert([
            'domain' => 'sf31-pending.example.com',
            'tenant_id' => $this->tenantA,
            'type' => 'custom',
            'dns_status' => 'pending',
        ]);

        app(TenantOperationService::class)->suspend($this->tenantA);

        $this->resetTenancyForPublicResolution();
        $this->getJson('http://sf31-pending.example.com/api/v1/storefront/catalog/products')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Tenant could not be resolved.');
    }

    public function test_suspension_is_tenant_scoped_sibling_keeps_serving(): void
    {
        app(TenantOperationService::class)->suspend($this->tenantA);

        $this->resetTenancyForPublicResolution();
        $this->getJson('http://sf31b.localhost/api/v1/storefront/catalog/products')
            ->assertStatus(200);

        $this->resetTenancyForPublicResolution();
        $this->getJson('http://sf31a.localhost/api/v1/storefront/catalog/products')
            ->assertStatus(503);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function createCentralTenant(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(
            ['id' => $id],
            ['name' => $name, 'email' => 'sf031-'.Str::random(8).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        return $id;
    }

    private function grantStorefront(string $tenantId): void
    {
        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'catalog_item_id' => $item->id],
            ['is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now()]
        );
        app(FeatureGateService::class)->invalidate($tenantId);
    }

    /**
     * Bare insert: the migration defaults (type=platform, dns_status=verified)
     * are exactly the platform-subdomain truth, so no onboarding round-trip
     * is needed for a servable host.
     */
    private function platformDomain(string $domain, string $tenantId): void
    {
        DB::table('public.domains')->updateOrInsert(
            ['domain' => $domain],
            ['tenant_id' => $tenantId]
        );
    }

    /**
     * Host-based resolution only runs when tenancy is NOT already
     * initialized (setUp binds a bootstrap context for the seeders — the
     * middleware short-circuits on that flag).
     */
    private function resetTenancyForPublicResolution(): void
    {
        $tenancy = app(Tenancy::class);
        $tenancy->initialized = false;
        $tenancy->tenant = null;
        $this->app->forgetInstance(\Stancl\Tenancy\Contracts\Tenant::class);
    }
}

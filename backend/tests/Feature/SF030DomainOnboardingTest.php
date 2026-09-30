<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\User;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use App\Tenancy\DnsResolver;
use App\Tenancy\SslProbe;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Storefront\Support\TenantUrl;
use Stancl\Tenancy\Tenancy;
use Tests\TenantTestCase;

/**
 * SF-030 — P5 / FR-PLAT-03 + A-26: domain onboarding.
 *
 * Proves, over real HTTP through the router:
 *   1. SuperAdmin + tenant can onboard custom/platform domains with
 *      DNS/SSL status surfaced (never a silent row insert);
 *   2. an UNVERIFIED custom domain host fails closed (404, no hijack)
 *      and the SAME host starts resolving only after the TXT ownership
 *      proof passes — the security invariant of the feature;
 *   3. status checks record honest state (SSL probe result, cert expiry,
 *      localhost never falsely marked failed);
 *   4. RBAC + cross-tenant scoping on both surfaces;
 *   5. TenantUrl email links follow the SSL status.
 */
class SF030DomainOnboardingTest extends TenantTestCase
{
    private string $tenantA;

    private string $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');

        $this->bindTenantContext('sf030-bootstrap');
        $this->seed(RolePermissionSeeder::class);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantA = $this->createCentralTenant('SF030 Tenant A');
        $this->tenantB = $this->createCentralTenant('SF030 Tenant B');
        $this->grantStorefront($this->tenantA);
        $this->grantStorefront($this->tenantB); // both hosts assert 200 after resolution (never a 403 red herring)

        // SetTenantSearchPath purges+reconnects the shared connection on every
        // real TenancyInitialized — fatal inside RefreshDatabase's transaction:
        // the request would run on a fresh session that cannot see this test's
        // uncommitted rows (entitlement resolve returns [] → spurious 403).
        // Search-path stays 'public' here (TenantTestCase migrations run every
        // schema into public), so a no-op listener is behaviour-identical.
        $this->app->instance(\App\Listeners\SetTenantSearchPath::class, new class
        {
            public function handle($event): void {}
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. SuperAdmin onboarding surface
    // ─────────────────────────────────────────────────────────────────────

    public function test_superadmin_can_onboard_platform_and_custom_domains(): void
    {
        $token = $this->adminToken();

        $platform = $this->withToken($token)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains",
            ['domain' => 'sf30a.localhost', 'type' => 'platform']
        );
        $platform->assertStatus(201)
            ->assertJsonPath('data.domain', 'sf30a.localhost')
            ->assertJsonPath('data.type', 'platform')
            ->assertJsonPath('data.dns_status', 'verified', 'platform zone is trusted at insert — no TXT round-trip')
            ->assertJsonPath('data.is_primary', true, 'first domain becomes primary');

        $custom = $this->withToken($token)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains",
            ['domain' => 'https://Shop.Example.COM:443/', 'type' => 'custom']
        );
        $custom->assertStatus(201)
            ->assertJsonPath('data.domain', 'shop.example.com', 'normalized: scheme/port/path/case stripped')
            ->assertJsonPath('data.dns_status', 'pending')
            ->assertJsonPath('data.verification_record.host', '_storefront-verify.shop.example.com')
            ->assertJsonPath('data.verification_record.type', 'TXT');
        $this->assertNotEmpty($custom->json('data.verification_token'));
        $this->assertNotNull($custom->json('data.verification_record.value'));

        $this->withToken($token)->getJson("/api/v1/admin/tenants/{$this->tenantA}/domains")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // The tenant detail payload carries the FULL list (was MIN(domain) only).
        $this->withToken($token)->getJson("/api/v1/admin/tenants/{$this->tenantA}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.domains');
    }

    public function test_validation_rules_and_error_codes(): void
    {
        $token = $this->adminToken();
        $base = "/api/v1/admin/tenants/{$this->tenantA}/domains";

        $this->withToken($token)->postJson($base, ['domain' => 'evil.localhost'])
            ->assertStatus(422)->assertJsonPath('code', 'platform_domain_forbidden');

        $this->withToken($token)->postJson($base, ['domain' => 'shop.example.com', 'type' => 'platform'])
            ->assertStatus(422)->assertJsonPath('code', 'not_platform_subdomain');

        $this->withToken($token)->postJson($base, ['domain' => 'not a domain'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_domain');

        $this->withToken($token)->postJson($base, ['domain' => 'dup.example.com'])->assertStatus(201);
        $this->withToken($token)->postJson($base, ['domain' => 'dup.example.com'])
            ->assertStatus(409)->assertJsonPath('code', 'domain_taken');

        $this->withToken($token)->postJson('/api/v1/admin/tenants/00000000-0000-0000-0000-000000000000/domains', [
            'domain' => 'x.example.com',
        ])->assertStatus(404)->assertJsonPath('code', 'tenant_not_found');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. The security invariant: unverified host fails closed (A-26)
    // ─────────────────────────────────────────────────────────────────────

    public function test_unverified_custom_domain_fails_closed_until_txt_proof(): void
    {
        $admin = $this->adminToken();
        $this->withToken($admin)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains",
            ['domain' => 'shop.example.com']
        )->assertStatus(201);

        $url = 'http://shop.example.com/api/v1/storefront/catalog/products';

        // Pending claim: host must NOT resolve (not to A, not to anyone).
        $this->resetTenancyForPublicResolution();
        $this->getJson($url)->assertStatus(404)
            ->assertJsonPath('message', 'Tenant could not be resolved.');

        // Publish the TXT record (fake resolver), verify → now it serves.
        $resolver = $this->fakeDns();
        $domainId = $this->domainId('shop.example.com');
        $resolver->records['_storefront-verify.shop.example.com'] = [
            $this->verificationToken('shop.example.com'),
        ];

        $this->withToken($admin)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains/{$domainId}/verify"
        )->assertStatus(200)->assertJsonPath('data.dns_status', 'verified');

        $this->resetTenancyForPublicResolution();
        $this->getJson($url)->assertStatus(200);

        // Wrong TXT → verify fails and the host immediately stops serving.
        $resolver2 = $this->fakeDns();
        $resolver2->records['_storefront-verify.shop.example.com'] = ['totally-different-value'];
        $this->withToken($admin)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains/{$domainId}/verify"
        )->assertStatus(200)->assertJsonPath('data.dns_status', 'failed');

        $this->resetTenancyForPublicResolution();
        $this->getJson($url)->assertStatus(404);

        // Platform subdomains skip TXT entirely and always serve.
        $this->withToken($admin)->postJson(
            "/api/v1/admin/tenants/{$this->tenantB}/domains",
            ['domain' => 'sf30b.localhost', 'type' => 'platform']
        )->assertStatus(201);
        $this->resetTenancyForPublicResolution();
        $this->getJson('http://sf30b.localhost/api/v1/storefront/catalog/products')
            ->assertStatus(200, 'platform subdomain resolves without any DNS proof');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. DNS/SSL status checks
    // ─────────────────────────────────────────────────────────────────────

    public function test_check_records_ssl_status_and_skips_localhost(): void
    {
        $this->fakeDns(); // no real DNS lookups in tests — TXT recheck resolves to "absent"
        $admin = $this->adminToken();
        $this->withToken($admin)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains",
            ['domain' => 'secure.example.com']
        )->assertStatus(201);
        $customId = $this->domainId('secure.example.com');

        $probe = $this->fakeSsl();
        $probe->result = ['ok' => true, 'expires_at' => '2027-01-15T00:00:00+00:00', 'error' => null];

        $this->withToken($admin)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains/{$customId}/check"
        )->assertStatus(200)
            ->assertJsonPath('data.ssl_status', 'active')
            ->assertJsonPath('data.cert_expires_at', '2027-01-15 00:00:00');
        $this->assertNotNull($this->withToken($admin)->getJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains"
        )->json("data.{$this->domainIndex('secure.example.com')}.last_checked_at"));

        $probe->result = ['ok' => false, 'expires_at' => null, 'error' => 'connection refused'];
        $this->withToken($admin)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains/{$customId}/check"
        )->assertStatus(200)
            ->assertJsonPath('data.ssl_status', 'failed')
            ->assertJsonPath('data.cert_expires_at', null, 'failed probe must not keep a stale expiry');

        // Localhost platform host: no TLS infrastructure exists there —
        // status stays 'unknown' and the probe is never even called.
        $probe->calls = 0;
        $this->withToken($admin)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains",
            ['domain' => 'sf30c.localhost', 'type' => 'platform']
        )->assertStatus(201);
        $localId = $this->domainId('sf30c.localhost');

        $this->withToken($admin)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains/{$localId}/check"
        )->assertStatus(200)->assertJsonPath('data.ssl_status', 'unknown');
        $this->assertSame(0, $probe->calls, 'localhost must never be TLS-probed');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. RBAC + cross-tenant scoping (both surfaces)
    // ─────────────────────────────────────────────────────────────────────

    public function test_tenant_side_rbac_and_scoping(): void
    {
        // Owner (settings.write) can onboard their own custom domain.
        $ownerA = $this->makeTenantUser($this->tenantA, 'owner', 'Owner');
        $this->bindTenantContext($this->tenantA);
        $created = $this->actingAs($ownerA)->postJson('/api/v1/tenant-domains', [
            'domain' => 'a-shop.example.com',
        ]);
        $created->assertStatus(201)->assertJsonPath('data.dns_status', 'pending');
        $aDomainId = (int) $created->json('data.id');

        // Manager is excluded from settings.view/write in the matrix.
        $managerA = $this->makeTenantUser($this->tenantA, 'manager', 'Manager');
        $this->bindTenantContext($this->tenantA);
        $this->actingAs($managerA)->getJson('/api/v1/tenant-domains')
            ->assertStatus(403)->assertJsonPath('required_permission', 'settings.view');
        $this->actingAs($managerA)->postJson('/api/v1/tenant-domains', ['domain' => 'm.example.com'])
            ->assertStatus(403)->assertJsonPath('required_permission', 'settings.write');

        // Tenants cannot self-assign the platform subdomain type.
        $this->actingAs($ownerA)->postJson('/api/v1/tenant-domains', [
            'domain' => 'platform.example.com', 'type' => 'platform',
        ])->assertStatus(403)->assertJsonPath('code', 'platform_domain_protected');

        // Tenant B sees ONLY its own rows and cannot touch A's row id.
        $ownerB = $this->makeTenantUser($this->tenantB, 'owner', 'Owner');
        $this->bindTenantContext($this->tenantB);
        $this->actingAs($ownerB)->getJson('/api/v1/tenant-domains')
            ->assertStatus(200)->assertJsonCount(0, 'data');
        $this->actingAs($ownerB)->deleteJson("/api/v1/tenant-domains/{$aDomainId}")
            ->assertStatus(404)->assertJsonPath('code', 'domain_not_found');
        $this->actingAs($ownerB)->postJson("/api/v1/tenant-domains/{$aDomainId}/verify")
            ->assertStatus(404);
        $this->assertNotNull($this->domainRow($aDomainId), "tenant B must not delete tenant A's domain");
    }

    public function test_superadmin_readonly_role_can_read_but_not_write(): void
    {
        $readonly = $this->adminToken('support_readonly');

        $this->withToken($readonly)->getJson("/api/v1/admin/tenants/{$this->tenantA}/domains")
            ->assertStatus(200);

        $this->withToken($readonly)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains",
            ['domain' => 'ro.example.com']
        )->assertStatus(403);
    }

    public function test_platform_domain_is_protected_from_deletion_and_custom_removal_promotes(): void
    {
        $admin = $this->adminToken();

        $this->withToken($admin)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains",
            ['domain' => 'keepme.localhost', 'type' => 'platform']
        )->assertStatus(201);
        $platformId = $this->domainId('keepme.localhost');

        $this->withToken($admin)->deleteJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains/{$platformId}"
        )->assertStatus(403)->assertJsonPath('code', 'platform_domain_protected');

        $customId = $this->domainId($this->addCustom($admin, 'gone.example.com'));
        $this->withToken($admin)->deleteJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains/{$customId}"
        )->assertStatus(200);
        $this->assertNull($this->domainRow($customId));
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. TenantUrl follows SSL status (FR-PLAT-03 feedback loop)
    // ─────────────────────────────────────────────────────────────────────

    public function test_tenanturl_links_follow_ssl_status(): void
    {
        $admin = $this->adminToken();

        $this->withToken($admin)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains",
            ['domain' => 'links.example.com', 'type' => 'custom']
        )->assertStatus(201);
        $id = $this->domainId('links.example.com');

        // unknown (localhost-ish rule doesn't apply) → best-effort https
        DB::table('public.domains')->where('id', $id)->update(['ssl_status' => 'unknown']);
        $this->bindTenantContext($this->tenantA);
        $this->assertSame('https://links.example.com', TenantUrl::base());

        DB::table('public.domains')->where('id', $id)->update(['ssl_status' => 'active']);
        $this->assertSame('https://links.example.com', TenantUrl::base());

        DB::table('public.domains')->where('id', $id)->update(['ssl_status' => 'failed']);
        $this->assertSame('http://links.example.com', TenantUrl::base(),
            'a failed certificate must not produce https links that bounce the customer');
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function adminToken(string $role = 'platform_super_admin'): string
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);

        if (in_array($role, User::PRIVILEGED_ROLES, true) && $user->role !== $role) {
            // The saving-guard strips privileged roles set outside the
            // dedicated path (Pillar 1 P0-0 fix #3) — use the real one.
            $user->assignAdminRole($role, null, 'SF030 domain onboarding test');
        }

        return $user->createToken('admin-token')->plainTextToken;
    }

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

    private function createCentralTenant(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(
            ['id' => $id],
            ['name' => $name, 'email' => 'sf030-'.Str::random(8).'@example.com', 'created_at' => now(), 'updated_at' => now()]
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

    private function fakeDns(): object
    {
        $resolver = new class extends DnsResolver
        {
            /** @var array<string, list<string>> */
            public array $records = [];

            public function txtRecords(string $host): array
            {
                return $this->records[$host] ?? [];
            }
        };

        $this->rebind(DnsResolver::class, $resolver);

        return $resolver;
    }

    private function fakeSsl(): object
    {
        $probe = new class extends SslProbe
        {
            public ?array $result = null;

            public int $calls = 0;

            public function probe(string $host): array
            {
                $this->calls++;

                return $this->result ?? ['ok' => false, 'expires_at' => null, 'error' => 'no result configured'];
            }
        };

        $this->rebind(SslProbe::class, $probe);

        return $probe;
    }

    /**
     * Container rebinding does NOT reach controllers already instantiated by
     * Route::getController() — Laravel caches the controller (and its
     * constructor-injected services) on the Route object for the lifetime of
     * the app, and a test app survives every request in the test. Flush the
     * cached controllers so the next dispatch re-resolves dependencies.
     */
    private function rebind(string $abstract, object $instance): void
    {
        $this->app->instance($abstract, $instance);

        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
    }

    private function domainRow(int $id): ?object
    {
        return DB::table('public.domains')->where('id', $id)->first();
    }

    private function domainId(string $domain): int
    {
        return (int) DB::table('public.domains')->where('domain', $domain)->value('id');
    }

    private function verificationToken(string $domain): string
    {
        return (string) DB::table('public.domains')->where('domain', $domain)->value('verification_token');
    }

    private function domainIndex(string $domain): int
    {
        $rows = DB::table('public.domains')->where('tenant_id', $this->tenantA)->orderBy('id')->get();
        foreach ($rows as $index => $row) {
            if ($row->domain === $domain) {
                return $index;
            }
        }

        $this->fail("domain {$domain} not found");
    }

    private function addCustom(string $adminToken, string $domain): string
    {
        $this->withToken($adminToken)->postJson(
            "/api/v1/admin/tenants/{$this->tenantA}/domains",
            ['domain' => $domain]
        )->assertStatus(201);

        return $domain;
    }
}

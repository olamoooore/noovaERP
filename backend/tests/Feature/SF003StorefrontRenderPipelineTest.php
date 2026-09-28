<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\CatalogQuery;
use Modules\Storefront\Services\StorefrontCache;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\PageVersion;
use Modules\WebsiteBuilder\Models\Website;
use Tests\TenantTestCase;

/**
 * SF-003 — Shortcode render pipeline over REAL HTTP (Appendix B §B10.2,
 * acceptance A-01/03/04, FR-BLD-05/09, FR-SF-07).
 *
 * Drives the production /w/{slug} renderer (PublicWebsiteRenderController +
 * the tagged page_content_filters pipeline) with a tenant context bound the
 * same way the tenant middleware does, and asserts HTML outcomes — not
 * source strings.
 *
 * Schema note: like every TenantTestCase, tenant tables live in `public`
 * here, so this file proves render pipeline + gating; TRUE per-tenant schema
 * isolation (identical page, different data, no crossover) is proven in
 * SF004StorefrontTwoTenantRenderTest with real Postgres schemas.
 */
class SF003StorefrontRenderPipelineTest extends TenantTestCase
{
    private string $tenantId;

    private string $websiteSlug;

    private int $ownerUserId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');
        $this->seed(RolePermissionSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF003 Tenant', 'email' => 'sf003-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        $this->provisionAndGrantStorefront($this->tenantId);

        $this->websiteSlug = 'sf003-shop-'.Str::lower(Str::random(6));
        $owner = User::factory()->create();
        $this->ownerUserId = (int) $owner->id;
        Website::create([
            'name' => 'SF003 Shop',
            'slug' => $this->websiteSlug,
            'status' => 'published',
            'created_by' => $owner->id,
            'published_at' => now(),
        ]);

        // currencies may be pre-seeded by tenant migrations (outside the test
        // transaction) — upsert the symbol we assert on.
        Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true],
        );
    }

    // ─── A-01: the canonical merchant journey render ───────────────────

    public function test_published_page_renders_product_grid_with_tenant_data(): void
    {
        $this->makePublishedPage('shop', '<section>[storefront.products limit="8"]</section>');
        $this->makeProduct(['name' => 'Alpha Mug', 'price' => 25.50, 'cost' => 9999.44]);

        // Unconfigured tenant currency ⇒ no symbol guessing (G5).
        $bare = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();
        $this->assertStringContainsString('25.50', $bare);
        $this->assertStringNotContainsString('$25.50', $bare);

        StorefrontSettings::current()->update(['currency_code' => 'USD']);
        $this->bindTenantContext($this->tenantId);

        $response = $this->get('/w/'.$this->websiteSlug.'/shop');
        $response->assertStatus(200);
        $html = $response->getContent();

        $this->assertStringContainsString('Alpha Mug', $html);
        $this->assertStringContainsString('$25.50', $html, 'Price must render in the tenant-configured currency (FR-SF-10)');
        $this->assertStringNotContainsString('[storefront.products', $html, 'Raw tokens must never reach visitors');
        $this->assertStringNotContainsString('9999.44', $html, 'Cost price must never appear in public HTML (FR-SF-07)');
        $this->assertStringContainsString('Prices exclude applicable tax', $html);
    }

    public function test_only_storefront_published_products_are_visible(): void
    {
        $this->makePublishedPage('shop', '[storefront.products]');
        $this->makeProduct(['name' => 'Public Item', 'show_on_website' => true]);
        $this->makeProduct(['name' => 'Secret Internal Item', 'show_on_website' => false]);
        $this->makeProduct(['name' => 'Draft Item', 'status' => 'draft', 'show_on_website' => true]);
        $this->makeProduct(['name' => 'Inactive Item', 'is_active' => false, 'show_on_website' => true]);

        $html = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();

        $this->assertStringContainsString('Public Item', $html);
        $this->assertStringNotContainsString('Secret Internal Item', $html);
        $this->assertStringNotContainsString('Draft Item', $html);
        $this->assertStringNotContainsString('Inactive Item', $html);
    }

    public function test_single_product_function_renders_by_slug_and_degrades_when_missing(): void
    {
        $product = $this->makeProduct(['name' => 'Detail Tee', 'slug' => 'detail-tee-1', 'show_on_website' => true]);
        $this->makePublishedPage('p', '[storefront.product slug="detail-tee-1" layout="detail"] [storefront.product id="999999"]');

        $html = $this->get('/w/'.$this->websiteSlug.'/p')->getContent();

        $this->assertStringContainsString('Detail Tee', $html);
        $this->assertStringContainsString('This product is not available.', $html, 'Missing/unpublished product must not leak existence (FR-SF-07)');
        $this->assertStringNotContainsString('id="999999"', $html);
    }

    // ─── A-03: hostile/invalid tokens never fatal ───────────────────────

    public function test_unknown_and_invalid_functions_never_fatal_or_leak(): void
    {
        $this->makeProduct(['name' => 'Alpha Mug']);
        $this->makePublishedPage('shop', '<p>a [storefront.not_a_function] b [storefront.products limit="abc" columns="99" evil="x"] c</p>');

        $response = $this->get('/w/'.$this->websiteSlug.'/shop');
        $response->assertStatus(200);
        $html = $response->getContent();

        $this->assertStringNotContainsString('storefront.not_a_function', $html);
        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringContainsString('Alpha Mug', $html, 'Invalid attribute values must degrade to safe defaults, not omit the function silently-wrong');
    }

    public function test_attribute_injection_cannot_reach_sql_or_break_the_page(): void
    {
        $this->makeProduct(['name' => 'Alpha Mug']);
        $hostile = '[storefront.products ids="1;DROP TABLE products" search="%\' OR 1=1 --"]';
        $this->makePublishedPage('shop', $hostile);

        $response = $this->get('/w/'.$this->websiteSlug.'/shop');
        $response->assertStatus(200);

        // ids list: nothing valid survives coercion; search: bounded param —
        // LIKE-matched, not concatenated. Either way the page renders and
        // the products table is intact.
        $this->assertGreaterThan(0, Product::query()->count(), 'products table must survive hostile attributes');
        $this->assertStringNotContainsString('storefront.products', $response->getContent());
    }

    public function test_tokens_inside_script_or_comments_are_ignored_not_executed(): void
    {
        $this->makeProduct(['name' => 'Alpha Mug']);
        $literal = '[storefront.products ids="999999"]';
        $this->makePublishedPage('shop', '<script>var keep = "'.$literal.'";</script><p>plain text</p>');

        $html = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();

        $this->assertStringContainsString($literal, $html, 'Script-string content must pass through untouched (parser region rule)');
        $this->assertStringNotContainsString('Alpha Mug', $html, '…and must not be treated as a function');
    }

    public function test_xss_payloads_in_product_fields_cannot_execute(): void
    {
        $this->makeProduct([
            'name' => 'Evil Mug <script>alert(1)</script>',
            'short_description' => '<img src=x onerror=alert(2)><b>bold ok</b>',
            'description' => '<script>alert(3)</script>plain desc',
        ]);
        $this->makePublishedPage('shop', '[storefront.products]');

        $html = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<script>alert(3)</script>', $html);
        $this->assertStringNotContainsString('onerror=', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('alert(1)', $html, 'Dangerous markup must degrade to escaped/innert text, not vanish silently');
    }

    // ─── A-04: entitlement + shop toggle degrade gracefully ─────────────

    public function test_unentitled_tenant_strips_functions_and_leaks_nothing(): void
    {
        $this->makeProduct(['name' => 'Alpha Mug']);
        $this->makePublishedPage('shop', '[storefront.products]');

        $otherTenant = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $otherTenant], [
            'name' => 'SF003 Unentitled', 'email' => 'sf003-nope-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($otherTenant);

        $response = $this->get('/w/'.$this->websiteSlug.'/shop');
        $response->assertStatus(200); // the PAGE still serves…
        $html = $response->getContent();
        $this->assertStringNotContainsString('Alpha Mug', $html); // …without shop data
        $this->assertStringNotContainsString('storefront', $html); // …and without raw tokens
    }

    public function test_merchant_disabled_shop_strips_functions(): void
    {
        $this->makeProduct(['name' => 'Alpha Mug']);
        $this->makePublishedPage('shop', '[storefront.products]');
        StorefrontSettings::current()->update(['enabled' => false]);

        $html = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();
        $this->assertStringNotContainsString('Alpha Mug', $html);
        $this->assertStringNotContainsString('storefront.products', $html);
    }

    // ─── FR-BLD-08 / R-03: caching + event-driven invalidation ──────────

    public function test_fragment_cache_serves_public_pages_and_model_events_invalidate(): void
    {
        $product = $this->makeProduct(['name' => 'Alpha Mug']);
        $this->makePublishedPage('shop', '[storefront.products]');

        $first = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();
        $this->assertStringContainsString('Alpha Mug', $first);

        // Silent bulk UPDATE bypasses model events — the cache must serve the
        // old (still-valid-per-policy) fragment: proves Tier A cache is live.
        Product::query()->where('id', $product->id)->update(['name' => 'Silent Rename']);
        $this->bindTenantContext($this->tenantId);
        $second = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();
        $this->assertStringContainsString('Alpha Mug', $second, 'Tier A fragment should serve from cache');
        $this->assertStringNotContainsString('Silent Rename', $second);

        // A model-level write fires the invalidation listeners → version bump.
        $product->fresh()->update(['name' => 'Event Renamed']);
        $this->bindTenantContext($this->tenantId);
        $third = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();
        $this->assertStringContainsString('Event Renamed', $third, 'Catalog model events must orphan stale fragments');
        $this->assertStringNotContainsString('Alpha Mug', $third);
    }

    public function test_cache_version_bumps_are_tenant_scoped(): void
    {
        $cache = app(StorefrontCache::class);
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $cache->bumpVersion($a);
        $this->assertSame(2, $cache->currentVersion($a));
        $this->assertSame(1, $cache->currentVersion($b), 'Tenant B must not be invalidated by Tenant A writes');
    }

    // ─── Query budget (B3.5 rule 5) ─────────────────────────────────────

    public function test_catalog_query_budget_is_bounded(): void
    {
        $cat = ProductCategory::create(['name' => 'Mugs', 'is_active' => true]);
        $this->makeProduct(['name' => 'Alpha Mug', 'category_id' => $cat->id]);
        $this->makeProduct(['name' => 'Beta Mug']);

        // Budget applies to the function's own queries (B3.5 rule 5):
        // list query + eager category (category filter adds exactly one
        // resolution query).
        $catalog = app(CatalogQuery::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $catalog->products($this->defaultProductAttrs([]));
        $bare = count(DB::getQueryLog());

        DB::flushQueryLog();
        $catalog->products($this->defaultProductAttrs(['category' => 'Mugs']));
        $withCategory = count(DB::getQueryLog());

        DB::flushQueryLog();
        $product = Product::query()->where('show_on_website', true)->first(['id']);
        DB::flushQueryLog();
        $catalog->product((int) $product->id, null);
        $single = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $bare, 'Unfiltered products must stay ≤ 2 queries');
        $this->assertLessThanOrEqual(3, $withCategory, 'Category-filtered products must stay ≤ 3 queries');
        $this->assertLessThanOrEqual(2, $single, 'Single product must stay ≤ 2 queries');
    }

    // ─── Editor API (FR-BLD-01/03/06) ───────────────────────────────────

    public function test_blocks_endpoint_is_registry_derived_and_gated(): void
    {
        $admin = $this->makeTenantUser($this->tenantId, 'admin', 'Admin');
        $response = $this->actingAs($admin)->getJson('/api/v1/website-builder/storefront/blocks');
        $response->assertStatus(200)
            ->assertJsonPath('data.block_group', 'Store');

        $keys = collect($response->json('data.functions'))->pluck('key');
        $this->assertContains('storefront.products', $keys);
        $this->assertContains('storefront.product', $keys);

        $products = collect($response->json('data.functions'))
            ->firstWhere('key', 'storefront.products');
        $this->assertSame('A', $products['tier']);
        $limit = collect($products['attributes'])->firstWhere('name', 'limit');
        $this->assertSame(48, $limit['max']);

        // Every editor route must carry the same live gates (FR-MOD-02/03).
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'website-builder/storefront')) {
                continue;
            }
            $this->assertContains('entitlement:storefront', $route->middleware(), $route->uri());
            $this->assertTrue(collect($route->middleware())->contains(
                fn (string $mw) => str_starts_with($mw, 'App\\Http\\Middleware\\RequirePermission:storefront.')
            ), $route->uri());
        }
    }

    public function test_preview_endpoint_renders_tenant_data_bypasses_cache_and_validates(): void
    {
        $this->makeProduct(['name' => 'Alpha Mug']);
        $admin = $this->makeTenantUser($this->tenantId, 'admin', 'Admin');

        $response = $this->actingAs($admin)->postJson('/api/v1/website-builder/storefront/preview', [
            'function' => 'storefront.products',
            'attributes' => ['limit' => '12'],
        ]);
        $response->assertStatus(200)
            ->assertHeader('X-Storefront-Preview', '1');
        $this->assertStringContainsString('Alpha Mug', $response->json('data.html'));
        $this->assertArrayHasKey('html', $response->json('data') ?? []);

        // Unknown function: explicit 422 the editor can surface.
        $this->actingAs($admin)->postJson('/api/v1/website-builder/storefront/preview', [
            'function' => 'storefront.does_not_exist',
        ])->assertStatus(422)->assertJsonPath('code', 'unknown_function');

        // Preview must not be reachable without entitlement.
        $other = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $other], [
            'name' => 'SF003 Nope', 'email' => 'sf003-nope-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $userB = $this->makeTenantUser($other, 'admin', 'Admin');
        $this->bindTenantContext($other);
        $this->actingAs($userB)->postJson('/api/v1/website-builder/storefront/preview', [
            'function' => 'storefront.products',
        ])->assertStatus(403);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function makePublishedPage(string $slug, string $html): Page
    {
        $website = Website::where('slug', $this->websiteSlug)->firstOrFail();
        $page = Page::create([
            'website_id' => $website->id,
            'title' => ucfirst($slug),
            'slug' => $slug,
            'is_home' => false,
            'status' => 'published',
            'sort_order' => 1,
        ]);
        PageVersion::create([
            'page_id' => $page->id,
            'created_by' => $this->ownerUserId,
            'version' => 1,
            'content' => ['html' => $html, 'css' => '', 'header_js' => '', 'body_js' => ''],
            'is_current' => true,
            'is_published' => true,
        ]);

        return $page;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeProduct(array $overrides): Product
    {
        $name = $overrides['name'] ?? 'Test Product '.Str::random(4);

        return Product::factory()->create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'show_on_website' => true,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'stock_quantity' => 10,
        ], $overrides));
    }

    private function provisionAndGrantStorefront(string $tenantId): void
    {
        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->create([
            'tenant_id' => $tenantId,
            'catalog_item_id' => $item->id,
            'is_entitled' => true,
            'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE,
            'resolved_at' => now(),
        ]);
        app(FeatureGateService::class)->invalidate($tenantId);
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
        $this->bindTenantContext($tenantId);

        return $user;
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultProductAttrs(array $overrides): array
    {
        return array_merge([
            'ids' => [],
            'category' => null,
            'collection' => null,
            'search' => null,
            'sort' => 'newest',
            'limit' => 12,
            'page' => 1,
            'columns' => 4,
            'layout' => 'grid',
            'in_stock_only' => false,
            'new_only' => false,
        ], $overrides);
    }
}

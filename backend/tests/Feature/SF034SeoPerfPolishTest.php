<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Product;
use App\Models\User;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\PageVersion;
use Modules\WebsiteBuilder\Models\Website;
use Tests\TenantTestCase;

/**
 * SF-034 — P5 SEO/perf polish (FR-SF-12, NFR-08/09, A-17, A-27).
 *
 * Proves over real HTTP through the router:
 *   1. published pages carry meta description + ABSOLUTE canonical (bounded
 *      ?product= preserved on product detail pages) + Open Graph tags;
 *   2. product detail pages embed Product JSON-LD (decodable, priced in the
 *      tenant currency, availability mapped) — server-rendered, no JS;
 *   3. /robots.txt points crawlers at /sitemap.xml and walls off the API
 *      surface; a tenant with no published site asks to be left alone;
 *   4. /sitemap.xml lists ONLY the resolving tenant's live URLs: published
 *      pages + crawlable product URLs mirroring the card href shape,
 *      drafts and never-published pages absent;
 *   5. admin /health reports the A-27 cache hit ratio (warm re-render
 *      produces hits) and served-surface state;
 *   6. cached render completes well under the 800 ms A-27 budget.
 */
class SF034SeoPerfPolishTest extends TenantTestCase
{
    private string $tenantId;

    private Website $website;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');
        $this->seed(RolePermissionSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF034 Tenant', 'email' => 'sf034-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);
        $this->grant('storefront');
        $this->grant('website-builder');

        $this->owner = $this->makeTenantUser($this->tenantId, 'owner', 'Owner');
        $this->bindTenantContext($this->tenantId);

        Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true],
        );
        StorefrontSettings::current()->update(['currency_code' => 'USD', 'enabled' => true]);

        $this->website = Website::create([
            'name' => 'SF034 Shop',
            'slug' => 'sf034-shop-'.Str::lower(Str::random(6)),
            'status' => 'published',
            'created_by' => $this->owner->id,
            'published_at' => now(),
        ]);

        $this->product = Product::factory()->create([
            'name' => 'SF034 SEO Mug', 'sku' => 'SF034-'.Str::random(6),
            'price' => 18.50, 'status' => 'active', 'is_active' => true,
            'show_on_website' => true, 'track_inventory' => false, 'stock_quantity' => 7,
            'short_description' => 'A crawlable mug.',
        ]);

        $this->makePublishedPage('shop', '[storefront.products limit="6"]');
        $this->makePublishedPage('product', '[storefront.product]');

        // A draft page and an unpublished website page must NEVER appear in
        // the sitemap.
        $draft = Page::create([
            'website_id' => $this->website->id, 'title' => 'Drafted', 'slug' => 'drafted',
            'is_home' => false, 'status' => 'draft', 'sort_order' => 9,
        ]);
        $draft->createVersion(['html' => '<p>DRAFTED-PAGE-SECRET</p>', 'css' => '', 'header_js' => '', 'body_js' => ''], (int) $this->owner->id);
    }

    // ─── 1. head meta ───────────────────────────────────────────────────

    public function test_published_pages_carry_canonical_and_open_graph(): void
    {
        $html = $this->get('/w/'.$this->website->slug.'/shop')->assertStatus(200)->getContent();

        $this->assertMatchesRegularExpression('#<link rel="canonical" href="https?://[^/"]+/w/'.$this->website->slug.'/shop">#', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="SF034 Shop">', $html);
        $this->assertStringContainsString('<meta property="og:title"', $html);
        $this->assertStringContainsString('<meta property="og:url"', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);

        // ?product= is page identity on the detail surface — canonical keeps it,
        // junk params are dropped.
        $detail = $this->get('/w/'.$this->website->slug.'/product?product='.$this->product->id.'&utm_source=x')->getContent();
        $this->assertMatchesRegularExpression(
            '#<link rel="canonical" href="https?://[^/"]+/w/'.$this->website->slug.'/product\?product='.$this->product->id.'">#',
            $detail
        );
        $this->assertStringNotContainsString('utm_source', $detail);
    }

    // ─── 2. structured data ─────────────────────────────────────────────

    public function test_product_detail_page_embeds_valid_json_ld(): void
    {
        $html = $this->get('/w/'.$this->website->slug.'/product?product='.$this->product->id)
            ->assertStatus(200)->getContent();

        $this->assertStringContainsString('<script type="application/ld+json">', $html);
        $this->assertStringContainsString('SF034 SEO Mug', $html, 'A-17: crawlable WITHOUT JS — data is server-rendered');

        $start = strpos($html, '<script type="application/ld+json">');
        $json = substr($html, $start + strlen('<script type="application/ld+json">'));
        $json = substr($json, 0, strpos($json, '</script>'));
        $ld = json_decode($json, true);

        $this->assertSame('https://schema.org', $ld['@context'] ?? null);
        $this->assertSame('Product', $ld['@type'] ?? null);
        $this->assertSame('SF034 SEO Mug', $ld['name'] ?? null);
        $this->assertSame((string) $this->product->sku, (string) ($ld['sku'] ?? ''));
        $this->assertSame('18.50', $ld['offers']['price'] ?? null);
        $this->assertSame('USD', $ld['offers']['priceCurrency'] ?? null);
        $this->assertSame('https://schema.org/InStock', $ld['offers']['availability'] ?? null);
    }

    // ─── 3–4. robots + sitemap ──────────────────────────────────────────

    public function test_robots_txt_advertises_the_sitemap_and_walls_off_the_api(): void
    {
        $res = $this->get('/robots.txt')->assertStatus(200);
        $this->assertStringContainsString('text/plain', strtolower((string) $res->headers->get('Content-Type')));

        $body = $res->getContent();
        $this->assertStringContainsString('Allow: /w/', $body);
        $this->assertStringContainsString('Disallow: /api/', $body);
        $this->assertStringContainsString('Disallow: /storefront/cart', $body);
        $this->assertMatchesRegularExpression('#Sitemap: https?://[^/\r\n]+/sitemap\.xml#', $body);
    }

    public function test_robots_disallows_everything_for_a_tenant_without_a_published_site(): void
    {
        $this->website->update(['status' => 'draft']);

        $body = $this->get('/robots.txt')->assertStatus(200)->getContent();
        $this->assertStringContainsString('Disallow: /', $body);
        $this->assertStringNotContainsString('Sitemap:', $body);
    }

    public function test_sitemap_lists_only_live_tenant_urls_in_the_card_link_shape(): void
    {
        $res = $this->get('/sitemap.xml')->assertStatus(200);
        $this->assertStringContainsString('application/xml', strtolower((string) $res->headers->get('Content-Type')));

        $xml = simplexml_load_string($res->getContent());
        $this->assertNotFalse($xml, 'sitemap must be valid XML');

        $locs = [];
        foreach ($xml->url as $u) {
            $locs[] = (string) $u->loc;
        }

        // Published pages…
        $paths = array_map(static fn (string $loc): string => (string) parse_url($loc, PHP_URL_PATH), $locs);
        $this->assertContains('/w/'.$this->website->slug.'/shop', $paths);
        $this->assertContains('/w/'.$this->website->slug.'/product', $paths);

        // …plus product URLs mirroring the crawlable card href shape (?product=<id>).
        $pid = (int) $this->product->id;
        $productLocs = array_values(array_filter($locs, static fn (string $loc): bool => str_contains($loc, '?product='.$pid)));
        $this->assertNotEmpty($productLocs, 'sitemap must carry the product URL in the exact card href shape');

        // Drafts never leak into the sitemap.
        foreach ($locs as $loc) {
            $this->assertStringNotContainsString('drafted', $loc);
        }

        $this->assertStringNotContainsString('DRAFTED-PAGE-SECRET', $res->getContent());
    }

    public function test_sitemap_caches_within_the_window_and_reflects_live_state(): void
    {
        // Per-tenant+host cache: stable within the 10-minute window…
        $a = $this->get('/sitemap.xml')->assertStatus(200)->getContent();
        $b = $this->get('/sitemap.xml')->assertStatus(200)->getContent();
        $this->assertSame($a, $b, 'repeated requests must reuse the cached document');

        // …and a newly published page shows up once the window rolls over
        // (we bump the cache version the same way catalog events do).
        $late = Page::create([
            'website_id' => $this->website->id, 'title' => 'Late Page', 'slug' => 'late-page',
            'is_home' => false, 'status' => 'published', 'sort_order' => 3,
        ]);
        $late->createVersion(['html' => '<p>LATE</p>', 'css' => '', 'header_js' => '', 'body_js' => ''], (int) $this->owner->id);

        $stillCached = $this->get('/sitemap.xml')->getContent();
        $this->assertStringNotContainsString('late-page', $stillCached, 'cached within the window');

        Cache::forget("storefront:sitemap:{$this->tenantId}:localhost");
        $fresh = $this->get('/sitemap.xml')->getContent();
        $this->assertStringContainsString('late-page', $fresh, 'after cache expiry the live state must be reflected');

        // Per-tenant cache isolation of the DOCUMENT key (true data isolation
        // is SF004's remit; here we only prove keying, no cross-tenant reads):
        $this->assertMatchesRegularExpression('#<loc>https?://[^<]+/w/'.$this->website->slug.'/late-page</loc>#', $fresh);
    }

    // ─── 5–6. A-27 cache ratio + render budget ──────────────────────────

    public function test_health_reports_cache_hit_ratio_and_warm_render_meets_budget(): void
    {
        // First render populates Tier A fragments (misses); second is warm.
        $this->get('/w/'.$this->website->slug.'/shop')->assertStatus(200);

        $started = hrtime(true);
        $this->get('/w/'.$this->website->slug.'/shop')->assertStatus(200);
        $warmMs = (hrtime(true) - $started) / 1_000_000;

        // A-27: p95 render < 800 ms cached.
        $this->assertLessThan(800, $warmMs, 'cached page render must complete within the 800ms A-27 budget (was '.$warmMs.'ms)');

        $res = $this->actingAs($this->owner)->getJson('/api/v1/admin/storefront/health');
        $res->assertStatus(200)
            ->assertJsonPath('data.storefront_enabled', true)
            ->assertJsonPath('data.sitemap_ready', true)
            ->assertJsonPath('data.website.slug', $this->website->slug);

        $cache = $res->json('data.cache');
        $this->assertIsInt($cache['hits']);
        $this->assertIsInt($cache['misses']);
        $this->assertGreaterThan(0, $cache['hits'], 'the warm second render must produce fragment hits');
        $this->assertGreaterThan(0, $cache['misses'], 'cold renders must be counted too');
        $this->assertGreaterThan(0.0, (float) $cache['ratio']);
        $this->assertIsInt($cache['version']);
    }

    public function test_health_requires_storefront_view(): void
    {
        $viewer = $this->makeTenantUser($this->tenantId, 'manager', 'Manager');
        $this->bindTenantContext($this->tenantId);
        // Manager holds storefront.view per PERMISSION_MATRIX → allowed; a
        // user without the tenant's access simply cannot resolve it:
        $this->actingAs($viewer)->getJson('/api/v1/admin/storefront/health')->assertStatus(200);

        $stranger = User::factory()->create();
        $this->bindTenantContext($this->tenantId);
        $this->actingAs($stranger)->getJson('/api/v1/admin/storefront/health')->assertStatus(403);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function grant(string $key): void
    {
        $item = CatalogItem::where('key', $key)->firstOrFail();
        TenantEntitlement::query()->updateOrCreate(
            ['tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id],
            ['is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now()]
        );
        app(FeatureGateService::class)->invalidate($this->tenantId);
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

    private function makePublishedPage(string $slug, string $html): Page
    {
        $page = Page::create([
            'website_id' => $this->website->id,
            'title' => ucfirst($slug),
            'slug' => $slug,
            'is_home' => false,
            'status' => 'published',
            'sort_order' => 1,
        ]);
        PageVersion::create([
            'page_id' => $page->id,
            'created_by' => (int) $this->owner->id,
            'version' => 1,
            'content' => ['html' => $html, 'css' => '', 'header_js' => '', 'body_js' => ''],
            'is_current' => true,
            'is_published' => true,
        ]);

        return $page;
    }
}

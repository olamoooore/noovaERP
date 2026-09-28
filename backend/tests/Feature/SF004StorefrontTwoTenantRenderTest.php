<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\PageVersion;
use Modules\WebsiteBuilder\Models\Website;
use Tests\TenantTestCase;

/**
 * SF-004 — A-02 two-tenant genericity gate (MRD G5/NFR-01, Appendix B §B10.4).
 *
 * Builds on the HR61 real-schema technique: TWO genuine Postgres schemas,
 * BYTE-IDENTICAL page content (the same merchant-authored tokens) and
 * different tenant data, served through the production /w/{slug} route.
 * Proves per-tenant isolation in: rendered HTML, currency formatting,
 * storefront settings, fragment cache, and product-id lookups (a schema-A
 * product id must resolve to NOTHING in schema B).
 *
 * EnsurePublicSchemaForAuth is excluded here because this harness switches
 * search_path per tenant manually (the middleware's own behavior is a
 * platform concern exercised on the real public path).
 */
class SF004StorefrontTwoTenantRenderTest extends TenantTestCase
{
    private const SCHEMA_A = 'sf004_tenant_a';

    private const SCHEMA_B = 'sf004_tenant_b';

    private const SHARED_HTML = '<section id="shop">[storefront.products]</section>'
        .'<section id="focus">[storefront.product id="{A_PRODUCT_ID}" layout="detail"]</section>';

    private const TENANT_TABLES = [
        'products', 'product_categories', 'product_audit_logs', 'storefront_settings',
        'wb_websites', 'wb_pages', 'wb_page_versions', 'currencies',
    ];

    private string $tenantIdA;

    private string $tenantIdB;

    private int $productIdA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\EnsurePublicSchemaForAuth::class);

        DB::statement('SET search_path TO public');
        $this->provisionCatalog();

        $this->tenantIdA = $this->provisionTenant(self::SCHEMA_A);
        $this->tenantIdB = $this->provisionTenant(self::SCHEMA_B);

        // Byte-identical authored content, different data — the genericity test.
        $this->seedTenantContent($this->tenantIdA, self::SCHEMA_A, [
            'currency' => ['USD', '$'],
            'tax_display' => 'exclusive',
            'product' => ['Alpha Mug', '10.00'],
        ]);
        $this->productIdA = Product::query()->max('id');

        $this->seedTenantContent($this->tenantIdB, self::SCHEMA_B, [
            'currency' => ['EUR', '€'],
            'tax_display' => 'inclusive',
            'product' => ['Beta Tee', '20.00'],
        ]);

        DB::statement('SET search_path TO public');
    }

    protected function tearDown(): void
    {
        DB::statement('SET search_path TO public');
        foreach ([self::SCHEMA_A, self::SCHEMA_B] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS \"{$schema}\" CASCADE");
        }
        TenantEntitlement::query()->whereIn('tenant_id', [$this->tenantIdA, $this->tenantIdB])->delete();

        parent::tearDown();
    }

    // ─── A-02: each tenant renders ONLY its own data ───────────────────

    public function test_identical_page_content_renders_each_tenants_own_catalog(): void
    {
        $htmlA = $this->renderShop($this->tenantIdA, self::SCHEMA_A);
        $this->assertStringContainsString('Alpha Mug', $htmlA);
        $this->assertStringContainsString('$10.00', $htmlA);
        $this->assertStringContainsString('Prices exclude applicable tax', $htmlA);
        $this->assertStringNotContainsString('Beta Tee', $htmlA, 'Tenant A must not see Tenant B data');
        $this->assertStringNotContainsString('€', $htmlA, 'Tenant B currency must not leak');
        $this->assertStringNotContainsString('[storefront.', $htmlA);

        $htmlB = $this->renderShop($this->tenantIdB, self::SCHEMA_B);
        $this->assertStringContainsString('Beta Tee', $htmlB);
        $this->assertStringContainsString('€20.00', $htmlB);
        $this->assertStringContainsString('Prices include applicable tax', $htmlB, 'Per-tenant tax display setting must be honored');
        $this->assertStringNotContainsString('Alpha Mug', $htmlB, 'Tenant B must not see Tenant A data');
        $this->assertStringNotContainsString('$10.00', $htmlB);
        $this->assertStringNotContainsString('[storefront.', $htmlB);
    }

    public function test_cross_tenant_product_id_lookups_resolve_to_nothing(): void
    {
        // The page embeds TENANT A's product id. Rendered in tenant B, the
        // id-scoped function must degrade to the generic missing state —
        // never B's data and never an existence oracle (FR-SF-07).
        $htmlB = $this->renderShop($this->tenantIdB, self::SCHEMA_B);

        $this->assertStringContainsString('This product is not available.', $htmlB);
        $this->assertStringNotContainsString('Alpha Mug', $htmlB);
    }

    public function test_fragment_cache_is_not_shared_between_tenants(): void
    {
        // Warm A's cache.
        $this->renderShop($this->tenantIdA, self::SCHEMA_A);

        // Mutate A's catalog through the MODEL (fires the invalidation
        // listener, bumping only A's cache version for A's bound context).
        $this->useTenantSchema(self::SCHEMA_A);
        $this->bindTenantContext($this->tenantIdA);
        $product = Product::query()->findOrFail($this->productIdA);
        $product->update(['name' => 'Alpha Mug v2']);

        // B renders cold — proves B never touched A's cached fragments.
        $htmlB = $this->renderShop($this->tenantIdB, self::SCHEMA_B);
        $this->assertStringContainsString('Beta Tee', $htmlB);
        $this->assertStringNotContainsString('Alpha Mug', $htmlB);
    }

    public function test_settings_and_enabled_toggle_are_per_tenant_schema(): void
    {
        // Disable the shop in B only.
        $this->useTenantSchema(self::SCHEMA_B);
        $this->bindTenantContext($this->tenantIdB);
        StorefrontSettings::current()->update(['enabled' => false]);

        $htmlB = $this->renderShop($this->tenantIdB, self::SCHEMA_B);
        $this->assertStringNotContainsString('Beta Tee', $htmlB, 'Disabled storefront B must strip functions…');
        $this->assertStringNotContainsString('[storefront.', $htmlB, '…without fatals or token leaks');

        $htmlA = $this->renderShop($this->tenantIdA, self::SCHEMA_A);
        $this->assertStringContainsString('Alpha Mug', $htmlA, '…while enabled storefront A keeps working');
    }

    // ─── Harness ────────────────────────────────────────────────────────

    private function renderShop(string $tenantId, string $schema): string
    {
        $this->bindTenantContext($tenantId);
        $this->useTenantSchema($schema);

        $response = $this->get('/w/sf004-shared-shop/shop');
        $response->assertStatus(200);

        return $response->getContent();
    }

    private function provisionCatalog(): void
    {
        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);
    }

    private function provisionTenant(string $schema): string
    {
        $tenant = Tenant::withoutEvents(function () use ($schema) {
            $t = Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => "SF004 {$schema}",
                'email' => 'sf004-'.Str::random(6).'@example.test',
            ]);
            $t->schema_name = $schema;
            $t->save();

            return $t;
        });

        DB::statement("CREATE SCHEMA IF NOT EXISTS \"{$schema}\"");
        foreach (self::TENANT_TABLES as $table) {
            // LIKE without constraints: same columns/defaults/indexes; FKs are
            // platform-managed per real tenant schema and irrelevant here.
            DB::statement("CREATE TABLE IF NOT EXISTS \"{$schema}\".{$table} (LIKE public.{$table} INCLUDING DEFAULTS INCLUDING INDEXES)");
        }

        // Entitle both tenants (central snapshot rows, real models).
        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $item->id,
            'is_entitled' => true,
            'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE,
            'resolved_at' => now(),
        ]);
        app(FeatureGateService::class)->invalidate($tenant->id);

        return (string) $tenant->id;
    }

    /**
     * @param  array{currency: array{0:string,1:string}, tax_display: string, product: array{0:string,1:string}}  $spec
     */
    private function seedTenantContent(string $tenantId, string $schema, array $spec): void
    {
        $this->useTenantSchema($schema);
        $this->bindTenantContext($tenantId);

        Currency::query()->updateOrCreate(
            ['code' => $spec['currency'][0]],
            ['name' => $spec['currency'][0], 'symbol' => $spec['currency'][1], 'decimal_places' => 2, 'is_active' => true],
        );

        $settings = StorefrontSettings::current();
        $settings->update([
            'currency_code' => $spec['currency'][0],
            'tax_display' => $spec['tax_display'],
            'enabled' => true,
        ]);

        $owner = User::factory()->create();
        $website = Website::create([
            'name' => 'Shared Slug Shop',
            // IDENTICAL slug in both schemas — same URL, different tenant.
            'slug' => 'sf004-shared-shop',
            'status' => 'published',
            'created_by' => $owner->id,
            'published_at' => now(),
        ]);

        $html = str_replace('{A_PRODUCT_ID}', (string) ($this->productIdA ?? 0), self::SHARED_HTML);

        $page = Page::create([
            'website_id' => $website->id,
            'title' => 'Shop',
            'slug' => 'shop',
            'is_home' => false,
            'status' => 'published',
            'sort_order' => 1,
        ]);
        PageVersion::create([
            'page_id' => $page->id,
            'version' => 1,
            'content' => ['html' => $html, 'css' => '', 'header_js' => '', 'body_js' => ''],
            'is_current' => true,
            'is_published' => true,
            'created_by' => $owner->id,
        ]);

        Product::factory()->create([
            'name' => $spec['product'][0],
            'slug' => Str::slug($spec['product'][0]),
            'price' => $spec['product'][1],
            'cost' => 7777.77,
            'sku' => 'SF004-'.Str::upper(Str::random(6)),
            'show_on_website' => true,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'stock_quantity' => 5,
            'short_description' => 'Nice item',
        ]);
    }

    private function useTenantSchema(string $schema): void
    {
        DB::statement("SET search_path TO \"{$schema}\", public");
    }
}

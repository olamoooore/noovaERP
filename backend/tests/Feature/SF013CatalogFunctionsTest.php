<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
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
use Modules\Storefront\Services\CatalogQuery;
use Modules\Storefront\Shortcodes\Context;
use Modules\Storefront\Shortcodes\StorefrontShortcodeRegistry;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\PageVersion;
use Modules\WebsiteBuilder\Models\Website;
use Tests\TenantTestCase;

/**
 * SF-013 — P1 catalog completion (FR-SF-03/04/08/09) over REAL HTTP:
 * categories with counts, shared collection page via ?sfcat=, search with
 * inline results, product detail with variant selection, and tenant-policy
 * availability labels that never leak raw counts.
 */
class SF013CatalogFunctionsTest extends TenantTestCase
{
    private string $tenantId;

    private string $websiteSlug;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF013 Tenant', 'email' => 'sf013-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->create([
            'tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id,
            'is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now(),
        ]);
        app(FeatureGateService::class)->invalidate($this->tenantId);

        $this->websiteSlug = 'sf013-'.Str::lower(Str::random(6));
        $owner = User::factory()->create();
        Website::create([
            'name' => 'SF013 Site', 'slug' => $this->websiteSlug, 'status' => 'published',
            'created_by' => $owner->id, 'published_at' => now(),
        ]);

        StorefrontSettings::current()->update(['currency_code' => null, 'allow_backorder' => false, 'low_stock_threshold' => 0]);
    }

    // ─── categories (FR-SF-03) ──────────────────────────────────────────

    public function test_categories_lists_names_with_visible_counts_and_links(): void
    {
        $mugs = ProductCategory::create(['name' => 'Mugs', 'is_active' => true]);
        ProductCategory::create(['name' => '<i>Empty Dept</i>', 'is_active' => true]);

        $this->makeProduct(['name' => 'Mug One', 'category_id' => $mugs->id]);
        $this->makeProduct(['name' => 'Mug Two', 'category_id' => $mugs->id]);
        $this->makeProduct(['name' => 'Hidden Mug', 'category_id' => $mugs->id, 'show_on_website' => false]);

        $this->makePublishedPage('shop', '[storefront.categories category_page="/w/'.$this->websiteSlug.'/collection"]');

        $html = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();

        $this->assertStringContainsString('Mugs', $html);
        $this->assertStringContainsString('2 products', $html, 'counts only visible products');
        $this->assertStringContainsString('?sfcat=Mugs', $html);
        $this->assertStringNotContainsString('<i>Empty', $html, 'category names are escaped');
        $this->assertStringNotContainsString('Hidden Mug', $html);
    }

    public function test_category_products_shared_page_via_sfcat_param(): void
    {
        $mugs = ProductCategory::create(['name' => 'Mugs', 'is_active' => true]);
        $tees = ProductCategory::create(['name' => 'Tees', 'is_active' => true]);
        $this->makeProduct(['name' => 'Only Mug Here', 'category_id' => $mugs->id]);
        $this->makeProduct(['name' => 'Only Tee Here', 'category_id' => $tees->id]);

        $this->makePublishedPage('collection', '[storefront.category_products limit="12"]');

        $mugHtml = $this->get('/w/'.$this->websiteSlug.'/collection?sfcat=Mugs')->getContent();
        $this->assertStringContainsString('Only Mug Here', $mugHtml);
        $this->assertStringNotContainsString('Only Tee Here', $mugHtml);

        $teeHtml = $this->get('/w/'.$this->websiteSlug.'/collection?sfcat=Tees')->getContent();
        $this->assertStringContainsString('Only Tee Here', $teeHtml);
        $this->assertStringNotContainsString('Only Mug Here', $teeHtml);

        // Hostile sfcat can't widen scope: treated as a (missing) category name.
        $evil = $this->get('/w/'.$this->websiteSlug.'/collection?sfcat='.urlencode("' OR 1=1 --"));
        $evil->assertStatus(200);
        $this->assertStringNotContainsString('Only Mug Here', $evil->getContent());
    }

    // ─── search (FR-SF-04) ──────────────────────────────────────────────

    public function test_search_renders_box_and_inline_initial_results(): void
    {
        $this->makeProduct(['name' => 'Searchable Mug', 'price' => 7.5]);
        $this->makeProduct(['name' => 'Unrelated Lamp']);

        $this->makePublishedPage('search', '[storefront.search query="mug" limit="5"]');

        $html = $this->get('/w/'.$this->websiteSlug.'/search')->getContent();
        $this->assertStringContainsString('name="sfq"', $html);
        $this->assertStringContainsString('Searchable Mug', $html);
        $this->assertStringNotContainsString('Unrelated Lamp', $html);
        $this->assertStringContainsString('7.5', $html);
    }

    public function test_catalog_feed_matches_renderer_rules(): void
    {
        $this->makeProduct(['name' => 'Feed Only Mug', 'cost' => 3333.33]);
        $this->makeProduct(['name' => 'Feed Hidden', 'show_on_website' => false]);

        $res = $this->getJson('/api/v1/storefront/catalog/products?search=mug');
        $res->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertStringContainsString('Feed Only Mug', $res->getContent());
        $this->assertStringNotContainsString('3333.33', $res->getContent());
        $this->assertArrayNotHasKey('cost', $res->json('data.0'));
    }

    // ─── variants (FR-SF-08) ────────────────────────────────────────────

    public function test_product_detail_extracts_variant_choices_with_prices(): void
    {
        $product = $this->makeProduct(['name' => 'Variant Tee', 'price' => 10]);
        ProductVariant::create(['product_id' => $product->id, 'name' => 'Blue', 'sku' => 'VT-B', 'price' => 12, 'cost' => 4, 'is_active' => true, 'track_inventory' => false]);
        ProductVariant::create(['product_id' => $product->id, 'name' => 'Red', 'sku' => 'VT-R', 'price' => 15, 'cost' => 4, 'is_active' => true, 'track_inventory' => false]);

        $this->makePublishedPage('p', '[storefront.product layout="detail"]');

        // ?product= id resolution on a merchant-built product page.
        $html = $this->get('/w/'.$this->websiteSlug.'/p?product='.$product->id)->getContent();
        $this->assertStringContainsString('Variant Tee', $html);
        $this->assertStringContainsString('name="sf_variant"', $html);
        $this->assertStringContainsString('Blue', $html);
        $this->assertStringContainsString('Red', $html);
        $this->assertStringNotContainsString('VT-B', $html);
        $this->assertStringNotContainsString('4.00', $html, 'variant cost must never render');

        // Garbage ?product= can't crash or widen anything.
        ($this->get('/w/'.$this->websiteSlug.'/p?product='.urlencode('1;DROP')))->assertStatus(200);
        $this->assertStringNotContainsString('Variant Tee', $this->get('/w/'.$this->websiteSlug.'/p?product=999999')->getContent());
    }

    public function test_cart_api_accepts_variant_from_detail_flow(): void
    {
        $product = $this->makeProduct(['name' => 'Variant Tee 2', 'price' => 10]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Blue', 'sku' => 'VT2-B', 'price' => 12, 'cost' => 4, 'is_active' => true, 'track_inventory' => false]);

        $this->postJson('/api/v1/storefront/cart/items', ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.unit_price', '12.00');
    }

    // ─── availability tiers (FR-SF-09) ──────────────────────────────────

    public function test_availability_labels_follow_tenant_policy_without_leaking_counts(): void
    {
        $tracked = $this->makeProduct(['name' => 'Low Stock Mug', 'track_inventory' => true, 'stock_quantity' => 3]);
        $gone = $this->makeProduct(['name' => 'Sold Out Mug', 'track_inventory' => true, 'stock_quantity' => 0]);

        StorefrontSettings::current()->update(['low_stock_threshold' => 5]);

        $this->makePublishedPage('shop', '[storefront.products limit="24"]');
        $html = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();
        $this->assertStringContainsString('Low stock', $html);
        $this->assertStringContainsString('Out of stock', $html);
        // The label itself must carry no count (the old assertion matched the
        // literal 'h3 ' of card headers and was a false-positive; scoped now).
        $this->assertMatchesRegularExpression('/sf-availability--low\">\s*Low stock\s*</', $html);

        // Tenant policy flip -> backorder label.
        StorefrontSettings::current()->update(['allow_backorder' => true]);
        $html2 = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();
        $this->assertStringContainsString('Available on backorder', $html2);
    }

    // ─── registry completeness + budgets ────────────────────────────────

    public function test_registry_exposes_new_functions_with_correct_tiers(): void
    {
        $registry = app(StorefrontShortcodeRegistry::class);
        foreach (['storefront.categories', 'storefront.category_products', 'storefront.search'] as $key) {
            $this->assertNotNull($registry->get($key), "{$key} registered");
            $this->assertTrue($registry->get($key)->isCacheable(), "{$key} is Tier A");
        }
    }

    public function test_categories_query_budget(): void
    {
        $mugs = ProductCategory::create(['name' => 'Budget Cats', 'is_active' => true]);
        $this->makeProduct(['category_id' => $mugs->id]);

        $ctx = new Context(tenantId: $this->tenantId, settings: StorefrontSettings::current());

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CatalogQuery::class)->categories($ctx);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $count, 'categories: one counted list query max + settings read');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function makePublishedPage(string $slug, string $html): void
    {
        $website = Website::where('slug', $this->websiteSlug)->firstOrFail();
        $page = Page::create([
            'website_id' => $website->id, 'title' => ucfirst($slug), 'slug' => $slug,
            'is_home' => false, 'status' => 'published', 'sort_order' => 1,
        ]);
        PageVersion::create([
            'page_id' => $page->id, 'version' => 1,
            'content' => ['html' => $html, 'css' => '', 'header_js' => '', 'body_js' => ''],
            'is_current' => true, 'is_published' => true,
            'created_by' => $website->created_by,
        ]);
    }

    /** @param  array<string,mixed>  $overrides */
    private function makeProduct(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'SF13 Product '.Str::random(4);

        return Product::factory()->create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'show_on_website' => true,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'stock_quantity' => 25,
            'price' => 10,
            'tax_rate' => 0,
        ], $overrides));
    }
}

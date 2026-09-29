<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Shortcodes\StorefrontShortcodeRegistry;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\PageVersion;
use Modules\WebsiteBuilder\Models\Website;
use Tests\TenantTestCase;

/**
 * SF-017 — Checkout island contracts (FR-CO-04/10, slice 4): registry Tier B
 * members, one-runtime rendering, SERVER-side terms acceptance, and hostile
 * URL attributes neutralized before they reach the shell.
 */
class SF017CheckoutIslandTest extends TenantTestCase
{
    private string $tenantId;

    private string $websiteSlug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withCredentials();
        Queue::fake();
        DB::statement('SET search_path TO public');

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF017 Tenant', 'email' => 'sf017-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->create([
            'tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id,
            'is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now(),
        ]);
        app(FeatureGateService::class)->invalidate($this->tenantId);

        StorefrontSettings::current();

        $this->websiteSlug = 'sf017-'.Str::lower(Str::random(6));
        $owner = User::factory()->create();
        Website::create([
            'name' => 'SF017 Site', 'slug' => $this->websiteSlug, 'status' => 'published',
            'created_by' => $owner->id, 'published_at' => now(),
        ]);
    }

    public function test_checkout_functions_are_registered_tier_b(): void
    {
        $registry = app(StorefrontShortcodeRegistry::class);

        foreach (['storefront.checkout', 'storefront.order_confirmation', 'storefront.pay_now'] as $key) {
            $def = $registry->get($key);
            $this->assertNotNull($def, "{$key} registered");
            $this->assertFalse($def->isCacheable(), "{$key} Tier B — never cached");
        }
    }

    public function test_checkout_pages_render_shells_with_one_runtime_and_no_personal_data(): void
    {
        $product = Product::factory()->create([
            'name' => 'S17 Mug '.Str::random(4), 'show_on_website' => true, 'is_active' => true,
            'status' => 'active', 'track_inventory' => false, 'stock_quantity' => 5, 'price' => 5, 'tax_rate' => 0,
        ]);

        $this->makePublishedPage('checkout', '[storefront.checkout][storefront.order_confirmation][storefront.pay_now]');

        $html = $this->get('/w/'.$this->websiteSlug.'/checkout')->getContent();

        $this->assertStringContainsString('data-sf-island="checkout"', $html);
        $this->assertStringContainsString('data-sf-island="order_confirmation"', $html);
        $this->assertStringContainsString('data-sf-island="pay_now"', $html);
        $this->assertSame(1, substr_count($html, '/storefront/assets/v1/storefront.js'));
        // No cart data leaks into the shell server-side.
        $this->assertStringNotContainsString($product->name, $html);
    }

    public function test_hostile_url_attributes_are_neutralized(): void
    {
        $this->makePublishedPage('checkout', '[storefront.checkout terms_url="javascript:alert(1)"]');
        $html = $this->get('/w/'.$this->websiteSlug.'/checkout')->getContent();
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data-sf-terms-url', $html, 'invalid scheme -> terms attribute omitted entirely');

        $this->makePublishedPage('ok', '[storefront.checkout terms_url="/p/terms"]');
        $html2 = $this->get('/w/'.$this->websiteSlug.'/ok')->getContent();
        $this->assertStringContainsString('data-sf-terms-url="/p/terms"', $html2);
    }

    public function test_terms_acceptance_is_enforced_server_side(): void
    {
        StorefrontSettings::current()->update(['checkout_terms_url' => '/p/terms']);

        [$token] = $this->cartWithProduct();
        $post = fn (array $extra) => $this->withUnencryptedCookie(\Modules\Storefront\Services\CartService::COOKIE, $token)
            ->postJson('/api/v1/storefront/checkout', array_merge([
                'email' => 's17-'.Str::random(6).'@example.test',
                'idempotency_key' => 'idem-'.Str::random(24),
                'shipping_address' => ['name' => 'A', 'address1' => 'B', 'city' => 'C', 'postal_code' => '1', 'country_code' => 'US'],
            ], $extra));

        $post([])->assertStatus(422)->assertJsonPath('code', 'terms_not_accepted');
        $post(['accepted_terms' => true])->assertStatus(201)->assertJsonPath('data.status', 'pending');

        StorefrontSettings::current()->update(['checkout_terms_url' => null]);
    }

    public function test_checkout_and_confirm_responses_expose_ids_for_the_islands(): void
    {
        [$token] = $this->cartWithProduct();

        $res = $this->withUnencryptedCookie(\Modules\Storefront\Services\CartService::COOKIE, $token)
            ->postJson('/api/v1/storefront/checkout', [
                'email' => 's17b-'.Str::random(6).'@example.test',
                'idempotency_key' => 'idem-'.Str::random(24),
                'shipping_address' => ['name' => 'A', 'address1' => 'B', 'city' => 'C', 'postal_code' => '1', 'country_code' => 'US'],
            ]);
        $res->assertStatus(201);
        $id = $res->json('data.id');
        $this->assertIsInt($id);

        $confirm = $this->getJson('/api/v1/storefront/orders/confirm/'.$res->json('data.confirmation_token'));
        $confirm->assertStatus(200)->assertJsonPath('data.id', $id);
        $this->assertSame($id, (int) StorefrontOrder::query()->where('order_number', $res->json('data.order_number'))->value('id'));
    }

    public function test_runtime_bundle_covers_the_checkout_flow(): void
    {
        $js = file_get_contents(public_path('storefront/assets/v1/storefront.js'));

        $this->assertStringContainsString("'/checkout'", $js);
        $this->assertStringContainsString('sfconf', $js);
        $this->assertStringContainsString('sf_pay_gateway', $js);
        $this->assertStringContainsString('accepted_terms', $js);
        // still: no money math, no raw HTML sinks, no external hosts
        foreach (['parseFloat', 'toFixed', 'innerHTML', 'eval(', 'document.write'] as $banned) {
            $this->assertStringNotContainsString($banned, $js);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function cartWithProduct(): array
    {
        $product = Product::factory()->create([
            'name' => 'S17 Mug '.Str::random(4), 'show_on_website' => true, 'is_active' => true,
            'status' => 'active', 'track_inventory' => false, 'stock_quantity' => 5, 'price' => 5, 'tax_rate' => 0,
        ]);

        $token = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $token), 'status' => 'active']);
        app(\Modules\Storefront\Services\CartService::class)->addOrUpdateItem($cart, (int) $product->id, null, 1);

        return [$token, $cart];
    }

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
}

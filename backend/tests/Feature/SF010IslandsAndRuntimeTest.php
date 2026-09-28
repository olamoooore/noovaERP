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
use Illuminate\Support\Str;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\PageVersion;
use Modules\WebsiteBuilder\Models\Website;
use Tests\TenantTestCase;

/**
 * SF-010 — Tier B islands + runtime bundle contract (FR-CO-01, FR-ID-01,
 * FR-BLD-01/05/09 §B3.4 tier rule, FR-PLAT-06 CSP/no-inline).
 *
 * Proves: Tier B functions are registry members that NEVER cache and never
 * server-render personal data; the public pipeline injects exactly one
 * versioned, tenant-neutral runtime include per page with islands; shells
 * escape hostile attributes; the shipped bundle keeps its contract.
 */
class SF010IslandsAndRuntimeTest extends TenantTestCase
{
    private string $tenantId;

    private string $websiteSlug;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF010 Tenant', 'email' => 'sf010-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);
        $this->grantStorefront($this->tenantId);

        $this->websiteSlug = 'sf010-'.Str::lower(Str::random(6));
        $owner = User::factory()->create();
        Website::create([
            'name' => 'SF010 Site', 'slug' => $this->websiteSlug, 'status' => 'published',
            'created_by' => $owner->id, 'published_at' => now(),
        ]);
    }

    public function test_registry_contains_all_p1_functions_as_uncached_tier_b(): void
    {
        $registry = app(\Modules\Storefront\Shortcodes\StorefrontShortcodeRegistry::class);

        foreach (['storefront.add_to_cart', 'storefront.cart', 'storefront.cart_drawer', 'storefront.cart_count', 'storefront.login', 'storefront.register', 'storefront.account'] as $key) {
            $def = $registry->get($key);
            $this->assertNotNull($def, "{$key} must be registered");
            $this->assertFalse($def->isCacheable(), "{$key} is Tier B — must NEVER enter the fragment cache");
        }

        // Cart/account DATA must not appear in shells: render a cart shell for
        // a tenant that HAS a cart with a product, then prove the shell has
        // zero cart content.
        $product = Product::factory()->create([
            'name' => 'Island Visible Product', 'show_on_website' => true, 'is_active' => true,
            'status' => 'active', 'track_inventory' => false, 'stock_quantity' => 5, 'price' => 5,
        ]);
        $cart = \Modules\Storefront\Models\StorefrontCart::create(['status' => 'active']);
        app(\Modules\Storefront\Services\CartService::class)->addOrUpdateItem($cart, $product->id, null, 1);

        $renderer = app(\Modules\Storefront\Shortcodes\Renderer::class);
        $shell = $renderer->renderToken('storefront.cart', [], new \Modules\Storefront\Shortcodes\Context(
            tenantId: $this->tenantId,
        ));

        $this->assertStringContainsString('data-sf-island="cart"', $shell);
        $this->assertStringNotContainsString('Island Visible Product', $shell, 'Tier B shells must not server-render personal cart data');
        $this->assertStringNotContainsString((string) $cart->id, $shell);
    }

    public function test_public_page_gets_one_runtime_bundle_and_escaped_shells(): void
    {
        $this->makePublishedPage('shop', '<div>[storefront.cart_count label="Bag"]</div><div>[storefront.cart show_checkout_button]</div>');

        $response = $this->get('/w/'.$this->websiteSlug.'/shop');
        $response->assertStatus(200);
        $html = $response->getContent();

        $this->assertStringContainsString('data-sf-island="cart_count"', $html);
        $this->assertStringContainsString('data-sf-island="cart"', $html);

        // exactly ONE runtime css+js include for a multi-island page
        $this->assertSame(1, substr_count($html, '/storefront/assets/v1/storefront.js'));
        $this->assertSame(1, substr_count($html, '/storefront/assets/v1/storefront.css'));
        $this->assertStringContainsString('defer', $html);
    }

    public function test_hostile_attributes_cannot_escape_the_shell(): void
    {
        $product = Product::factory()->create([
            'name' => 'Ok Product', 'show_on_website' => true, 'is_active' => true,
            'status' => 'active', 'track_inventory' => false, 'stock_quantity' => 5, 'price' => 5,
        ]);

        $token = '[storefront.add_to_cart product_id="'.$product->id.'"'
            .' label="<i>alert(1)</i>"'
            .' redirect="javascript:alert(2)"'
            .' onload="alert(3)"]';
        $this->makePublishedPage('shop', '<div>'.$token.'</div>');

        $html = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();

        $this->assertStringContainsString('data-sf-island="add_to_cart"', $html, 'valid function still renders');
        $this->assertStringNotContainsString('<i>alert(1)</i>', $html, 'label must be escaped, never injected as markup');
        $this->assertStringNotContainsString('javascript:', $html, 'redirect URLs are scheme-allowlisted (§B3.5: attributes are data, never code)');
        $this->assertStringNotContainsString('onload=', $html, 'unknown attributes are dropped, never echoed');
    }

    public function test_missing_product_id_degrades_by_policy(): void
    {
        $this->makePublishedPage('shop', '<p>a [storefront.add_to_cart] b</p>');

        // Production: nothing visible, page alive.
        $html = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();
        $this->assertStringNotContainsString('storefront.add_to_cart', $html);
        $this->assertStringContainsString('a ', $html);
    }

    public function test_runtime_bundle_ships_and_honours_its_contract(): void
    {
        $js = file_get_contents(public_path('storefront/assets/v1/storefront.js'));
        $css = file_get_contents(public_path('storefront/assets/v1/storefront.css'));

        $this->assertNotFalse($js);
        // tenant-neutral: no brands/hosts/absolute endpoints
        $this->assertStringNotContainsString('http://', $js);
        $this->assertStringNotContainsString('https://', $js);
        $this->assertStringNotContainsString('infiniti', strtolower($js));
        // talks to OUR api base only
        $this->assertStringContainsString("'/api/v1/storefront'", $js);
        // same-origin credentials + CSRF echo for session flows
        $this->assertStringContainsString('same-origin', $js);
        $this->assertStringContainsString('X-CSRF-Token', $js);
        // money pass-through: never parses/edits amounts
        $this->assertStringNotContainsString('parseFloat', $js);
        $this->assertStringNotContainsString('toFixed', $js);
        // XSS posture: DOM built via textContent only — no raw HTML sinks
        $this->assertStringNotContainsString('innerHTML', $js);
        $this->assertStringNotContainsString('eval(', $js);
        $this->assertStringNotContainsString('document.write', $js);
        // CSS carries design tokens, no brand literals
        $this->assertStringContainsString('--sf-primary', $css);
    }

    public function test_editor_blocks_endpoint_exposes_the_new_functions(): void
    {
        $admin = $this->makeTenantUser($this->tenantId, 'admin', 'Admin');
        $response = $this->actingAs($admin)->getJson('/api/v1/website-builder/storefront/blocks');
        $response->assertStatus(200);

        $keys = collect($response->json('data.functions'))->pluck('key');
        foreach (['storefront.cart', 'storefront.cart_count', 'storefront.login', 'storefront.add_to_cart'] as $key) {
            $this->assertTrue($keys->contains($key), "{$key} missing from blocks payload");
        }

        $cart = collect($response->json('data.functions'))->firstWhere('key', 'storefront.cart');
        $this->assertSame('B', $cart['tier']);
        $this->assertFalse($cart['cacheable']);
    }

    public function test_unentitled_page_strips_islands_and_ships_no_runtime(): void
    {
        $other = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $other], [
            'name' => 'SF010 Nope', 'email' => 'sf010-nope-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->makePublishedPage('shop', '[storefront.cart]');

        $this->bindTenantContext($other);
        $html = $this->get('/w/'.$this->websiteSlug.'/shop')->getContent();

        $this->assertStringNotContainsString('data-sf-island', $html);
        $this->assertStringNotContainsString('/storefront/assets/', $html, 'No runtime on stripped (disabled) pages');
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

    private function grantStorefront(string $tenantId): void
    {
        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'catalog_item_id' => $item->id],
            ['is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now()]
        );
        app(FeatureGateService::class)->invalidate($tenantId);
    }
}

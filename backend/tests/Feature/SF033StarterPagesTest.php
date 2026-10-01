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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Storefront\Support\StarterPageTemplates;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\Website;
use Tests\TenantTestCase;

/**
 * SF-033 — P5 starter store page templates (Appendix-B §B4.4, Appendix-E
 * §E4.3 `POST /website-builder/storefront/pages/starter`).
 *
 * Proves over real HTTP through the router:
 *   1. "Insert store pages" creates the recommended pages as ORDINARY
 *      builder pages carrying storefront function tokens;
 *   2. templates carry NO tenant-specific content (G5) — the stored HTML
 *      equals the placeholder templates verbatim;
 *   3. idempotent by slug — existing pages are skipped, never overwritten;
 *   4. new pages are DRAFTS: invisible to the public until the merchant
 *      publishes (P5 truthfulness), and a real publish makes the shop
 *      render REAL tenant products;
 *   5. RBAC: storefront.write required, entitlement required;
 *   6. no builder website yet ⇒ honest 422 (no silent website invention).
 */
class SF033StarterPagesTest extends TenantTestCase
{
    private string $tenantId;

    private User $owner;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');
        $this->seed(RolePermissionSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF033 Tenant', 'email' => 'sf033-'.Str::random(6).'@example.test',
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

        $this->website = Website::create([
            'name' => 'SF033 Shop',
            'slug' => 'sf033-shop-'.Str::lower(Str::random(6)),
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_insert_creates_the_recommended_pages_with_function_tokens(): void
    {
        $res = $this->actingAs($this->owner)->postJson('/api/v1/website-builder/storefront/pages/starter');
        $res->assertStatus(201)
            ->assertJsonPath('data.website.slug', $this->website->slug)
            ->assertJsonCount(8, 'data.created')
            ->assertJsonCount(0, 'data.skipped');

        $slugs = collect(StarterPageTemplates::pages($this->website->slug))->pluck('slug')->all();
        $res->assertJsonPath('data.created', $slugs);

        foreach (StarterPageTemplates::pages($this->website->slug) as $template) {
            $page = Page::where('website_id', $this->website->id)->where('slug', $template['slug'])->firstOrFail();
            $this->assertSame($template['title'], $page->title);
            $this->assertSame('draft', $page->status, 'starter pages land as drafts, not fake-published');
            $this->assertSame(
                $template['html'],
                (string) $page->currentVersion?->content['html'],
                "stored content for {$template['slug']} must be the placeholder template verbatim"
            );
            $this->assertStringContainsString('[storefront.', $page->currentVersion->content['html']);
        }
    }

    public function test_templates_contain_no_tenant_specific_data_g5(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/website-builder/storefront/pages/starter')->assertStatus(201);

        $html = '';
        foreach (['shop', 'checkout', 'account', 'order-confirmation'] as $slug) {
            $page = Page::where('slug', $slug)->firstOrFail();
            $html .= (string) $page->currentVersion?->content['html'];
        }

        // The site's OWN slug may appear as the product_page locator (structural
        // wiring the merchant could type by hand); tenant identity/data may not.
        foreach (['SF033 Tenant', $this->tenantId, '@example.test'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $html, 'templates must be tenant-neutral (G5)');
        }
    }

    public function test_insert_is_idempotent_and_never_overwrites(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/website-builder/storefront/pages/starter')->assertStatus(201);

        // Merchant customizes one page…
        $cart = Page::where('website_id', $this->website->id)->where('slug', 'cart')->firstOrFail();
        $cart->createVersion(['html' => '<h1>MY CART</h1>', 'css' => '', 'header_js' => '', 'body_js' => ''], (int) $this->owner->id);

        // …then clicks "Insert store pages" again.
        $again = $this->actingAs($this->owner)->postJson('/api/v1/website-builder/storefront/pages/starter');
        $again->assertStatus(200)
            ->assertJsonCount(0, 'data.created')
            ->assertJsonCount(8, 'data.skipped');

        $this->assertSame('<h1>MY CART</h1>', (string) $cart->fresh()->currentVersion?->content['html'], 'a re-insert must never clobber merchant edits');
        $this->assertSame(8, Page::where('website_id', $this->website->id)->whereIn('slug', collect(StarterPageTemplates::pages())->pluck('slug')->all())->count());
    }

    public function test_starter_pages_are_invisible_until_published_then_render_real_products(): void
    {
        $product = Product::factory()->create([
            'name' => 'SF033 Visible Mug', 'sku' => 'SF033-'.Str::random(6),
            'price' => 12.00, 'status' => 'active', 'is_active' => true,
            'show_on_website' => true, 'track_inventory' => false, 'stock_quantity' => 5,
        ]);

        $this->actingAs($this->owner)->postJson('/api/v1/website-builder/storefront/pages/starter')->assertStatus(201);

        // Drafts are NOT publicly reachable (P5 truthfulness gate).
        $this->get('/w/'.$this->website->slug.'/shop')->assertStatus(404);

        $this->actingAs($this->owner)->postJson("/api/v1/website-builder/websites/{$this->website->id}/publish")->assertStatus(200);

        $html = $this->get('/w/'.$this->website->slug.'/shop')->assertStatus(200)->getContent();
        $this->assertStringContainsString('SF033 Visible Mug', $html, 'starter shop page must render the real catalog after publish');
        $this->assertStringContainsString(
            'href="/w/'.$this->website->slug.'/product?product='.$product->id.'"',
            $html,
            'FR-SF-12/A-17: cards must be crawlable <a href>s to the product page (no JS needed)'
        );
        $this->assertStringNotContainsString('[storefront.products', $html, 'function tokens must be consumed, never leak');

        // And the token stays in the EDITOR view (page content), not the visitor HTML.
        $page = Page::where('website_id', $this->website->id)->where('slug', 'shop')->firstOrFail();
        $this->assertStringContainsString('[storefront.products', (string) $page->servedVersion()?->content['html']);
    }

    public function test_rbac_entitlement_and_no_website_honesty(): void
    {
        $viewer = $this->makeTenantUser($this->tenantId, 'viewer', 'Viewer');
        $this->bindTenantContext($this->tenantId);

        // storefront.write is required — the seeder's Viewer lacks it (Owner/
        // Admin bypass; Manager/SALES hold write, VIEWER holds view only).
        $this->actingAs($viewer)->postJson('/api/v1/website-builder/storefront/pages/starter')
            ->assertStatus(403)->assertJsonPath('required_permission', 'storefront.write');

        // Tenant without the storefront entitlement → gated.
        $bareTenant = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $bareTenant], [
            'name' => 'SF033 Bare', 'email' => 'sf033bare-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bareUser = $this->makeTenantUser($bareTenant, 'owner', 'Owner');
        $this->bindTenantContext($bareTenant);
        $this->actingAs($bareUser)->postJson('/api/v1/website-builder/storefront/pages/starter')
            ->assertStatus(403);

        // Entitled tenant WITHOUT a builder website → honest 422, no pages
        // invented. (Under this harness every tenant's data lives in the
        // shared public schema, so the "no website" state must be produced
        // by clearing the tables — in production each tenant schema starts
        // genuinely empty; same isolation caveat as prior SF suites.)
        Page::query()->forceDelete();
        Website::query()->forceDelete();
        $noSite = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $noSite], [
            'name' => 'SF033 NoSite', 'email' => 'sf033site-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->grantFor($noSite, 'storefront');
        $this->grantFor($noSite, 'website-builder');
        $noSiteUser = $this->makeTenantUser($noSite, 'owner', 'Owner');
        $this->bindTenantContext($noSite);
        $this->actingAs($noSiteUser)->postJson('/api/v1/website-builder/storefront/pages/starter')
            ->assertStatus(422)->assertJsonPath('code', 'no_website');
        $this->assertSame(0, Page::count(), 'no website must mean no pages created');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function grant(string $key): void
    {
        $this->grantFor($this->tenantId, $key);
    }

    private function grantFor(string $tenantId, string $key): void
    {
        $item = CatalogItem::where('key', $key)->firstOrFail();
        TenantEntitlement::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'catalog_item_id' => $item->id],
            ['is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now()]
        );
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

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Product;
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
use Modules\Storefront\Models\StorefrontAccount;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-008 — Server-authoritative cart runtime over REAL HTTP
 * (FR-CO-02/03; acceptance A-10 "tampered client prices ignored",
 * A-11-adjacent cookie/guest handling, A-04 entitlement behavior on the
 * public group, FR-PLAT-05 throttles, FR-SF-07 no-internal-fields on the
 * catalog feed).
 */
class SF008CartRuntimeTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        // JSON test requests only carry cookies when credentials are enabled;
        // the cart guest token lives in an httpOnly cookie by design (D3).
        $this->withCredentials();

        DB::statement('SET search_path TO public');

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF008 Tenant', 'email' => 'sf008-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);
        $this->grantStorefront($this->tenantId);

        Currency::query()->updateOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true]);
        \Modules\Storefront\Models\StorefrontSettings::current()->update(['currency_code' => 'USD']);

        // Product factory audit attribution needs a tenant user (platform
        // ProductAuditLog contract).
        if (! \App\Models\User::query()->exists()) {
            User::factory()->create();
        }
    }

    // ─── A-10 / FR-CO-03: server-authoritative totals ──────────────────

    public function test_guest_cart_issued_with_http_only_lax_cookie(): void
    {
        $response = $this->getJson('/api/v1/storefront/cart');
        $response->assertStatus(200)->assertJsonPath('data.items', []);

        $cookie = $this->grabCookie($response);
        $this->assertNotNull($cookie, 'Guest cart must receive a cookie');
        $this->assertTrue($cookie->isHttpOnly(), 'D3: no localStorage tokens');
        $sameSite = $cookie->getSameSite();
        $this->assertSame('lax', $sameSite instanceof \BackedEnum ? $sameSite->value : (string) $sameSite);
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame(64, strlen($cookie->getValue()), '32-byte hex token');

        // The raw token is NEVER what we store — only its SHA-256.
        $this->assertSame(
            1,
            StorefrontCart::query()->where('guest_token_hash', hash('sha256', $cookie->getValue()))->count(),
        );
    }

    public function test_totals_are_computed_server_side_and_client_prices_ignored(): void
    {
        $product = $this->makeProduct(['price' => 25.50, 'tax_rate' => 10, 'stock_quantity' => 50]);

        [$token, $cart] = $this->freshGuestCart();

        // Hostile client: assert its OWN price math is structurally ignored.
        $response = $this->withUnencryptedCookie(CartService::COOKIE, $token)->postJson('/api/v1/storefront/cart/items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 0.01,
            'unit_price' => 0.01,
            'total' => 0.02,
        ]);

        $response->assertStatus(200);

        // Server truth: 2 x 25.50 = 51.00 net, 10% = 5.10 tax, 56.10 gross.
        // Money is emitted as 2-decimal strings (currency contract).
        $response->assertJsonPath('data.totals.subtotal', '51.00')
            ->assertJsonPath('data.totals.tax', '5.10')
            ->assertJsonPath('data.totals.total', '56.10')
            ->assertJsonPath('data.items.0.unit_price', '25.50')
            ->assertJsonPath('data.item_count', 2);

        $this->assertStringNotContainsString('"price":0.01', $response->getContent());

        $cart->refresh();
        $line = $cart->items()->first();
        $this->assertSame('2.000', (string) $line->quantity, 'single add of qty 2 lands one line');
    }

    public function test_same_product_accumulates_one_line_variant_lines_are_distinct(): void
    {
        $product = $this->makeProduct(['price' => 10]);
        $variantA = ProductVariant::create(['product_id' => $product->id, 'name' => 'Blue', 'sku' => 'VAR-A-'.Str::random(4), 'price' => 12, 'cost' => 1, 'is_active' => true]);
        $variantB = ProductVariant::create(['product_id' => $product->id, 'name' => 'Red', 'sku' => 'VAR-B-'.Str::random(4), 'price' => 15, 'cost' => 1, 'is_active' => true]);

        [$token] = $this->freshGuestCart();
        $http = fn () => $this->withUnencryptedCookie(CartService::COOKIE, $token);

        $http()->postJson('/api/v1/storefront/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertStatus(200);
        $http()->postJson('/api/v1/storefront/cart/items', ['product_id' => $product->id, 'quantity' => 3])->assertStatus(200);

        // plain line + two variant lines = 3 lines, qty 5 total on plain
        $data = $http()->getJson('/api/v1/storefront/cart')->assertStatus(200)->json('data');
        $this->assertCount(1, $data['items']);
        $this->assertSame(5.0, (float) $data['items'][0]['quantity']);

        $http()->postJson('/api/v1/storefront/cart/items', ['product_id' => $product->id, 'variant_id' => $variantA->id])->assertStatus(200);
        $http()->postJson('/api/v1/storefront/cart/items', ['product_id' => $product->id, 'variant_id' => $variantB->id])->assertStatus(200);

        $data = $http()->getJson('/api/v1/storefront/cart')->json('data');
        $this->assertCount(3, $data['items']);

        // Variant price honored (canonical pricing path receives variantId)
        $red = collect($data['items'])->firstWhere('variant_id', $variantB->id);
        $this->assertSame(15.0, (float) $red['unit_price']);

        // Foreign variant id rejected
        $other = $this->makeProduct(['price' => 5]);
        $http()->postJson('/api/v1/storefront/cart/items', ['product_id' => $other->id, 'variant_id' => $variantA->id])
            ->assertStatus(422)->assertJsonPath('code', 'variant_not_available');
    }

    public function test_stock_policy_and_purchasability_enforced_server_side(): void
    {
        // not published to storefront channel
        $hidden = $this->makeProduct(['show_on_website' => false]);
        [$token] = $this->freshGuestCart();
        $this->withUnencryptedCookie(CartService::COOKIE, $token)
            ->postJson('/api/v1/storefront/cart/items', ['product_id' => $hidden->id])
            ->assertStatus(422)->assertJsonPath('code', 'product_not_available');

        // tracked stock: 3 on hand, request 5 -> reject under default policy
        $scarce = $this->makeProduct(['track_inventory' => true, 'stock_quantity' => 3, 'price' => 4]);
        $denied = $this->withUnencryptedCookie(CartService::COOKIE, $token)
            ->postJson('/api/v1/storefront/cart/items', ['product_id' => $scarce->id, 'quantity' => 5])
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('errors.available', 3);

        // tenant policy flip -> backorder allowed (no raw counts beyond policy)
        \Modules\Storefront\Models\StorefrontSettings::current()->update(['allow_backorder' => true]);
        $this->withUnencryptedCookie(CartService::COOKIE, $token)
            ->postJson('/api/v1/storefront/cart/items', ['product_id' => $scarce->id, 'quantity' => 5])
            ->assertStatus(200);
    }

    public function test_quantity_bounds_rejected(): void
    {
        $product = $this->makeProduct(['price' => 2]);
        [$token] = $this->freshGuestCart();

        foreach ([0, -3, 100000] as $qty) {
            $this->withUnencryptedCookie(CartService::COOKIE, $token)
                ->postJson('/api/v1/storefront/cart/items', ['product_id' => $product->id, 'quantity' => $qty])
                ->assertStatus(422);
        }
    }

    // ─── Item update/remove + IDOR ──────────────────────────────────────

    public function test_update_and_remove_requote_and_idor_is_rejected(): void
    {
        $product = $this->makeProduct(['price' => 7]);
        [$tokenA] = $this->freshGuestCart();

        $added = $this->withUnencryptedCookie(CartService::COOKIE, $tokenA)
            ->postJson('/api/v1/storefront/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertStatus(200)->json('data.items.0.id');

        $this->withUnencryptedCookie(CartService::COOKIE, $tokenA)
            ->putJson("/api/v1/storefront/cart/items/{$added}", ['quantity' => 5])
            ->assertStatus(200)
            ->assertJsonPath('data.totals.subtotal', '35.00');

        // A DIFFERENT guest's cart must not touch the line (IDOR guard).
        // Built directly (not via a second GET) because the test client keeps
        // sending the already-set sf_guest jar within one test.
        $tokenB = bin2hex(random_bytes(32));
        StorefrontCart::create([
            'guest_token_hash' => hash('sha256', $tokenB),
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $this->withUnencryptedCookie(CartService::COOKIE, $tokenB)
            ->putJson("/api/v1/storefront/cart/items/{$added}", ['quantity' => 99])
            ->assertStatus(404)->assertJsonPath('code', 'item_not_found');

        // line belongs to cart A only if A still sees it; then delete
        $this->withUnencryptedCookie(CartService::COOKIE, $tokenA)
            ->deleteJson("/api/v1/storefront/cart/items/{$added}")
            ->assertStatus(200)->assertJsonPath('data.items', []);
    }

    public function test_garbage_cookie_is_ignored_and_rotates_to_a_valid_cart(): void
    {
        $this->withUnencryptedCookie(CartService::COOKIE, str_repeat('z', 200))
            ->getJson('/api/v1/storefront/cart')
            ->assertStatus(200)
            ->assertJsonPath('data.items', []);

        $this->withUnencryptedCookie(CartService::COOKIE, '../../etc/passwd')
            ->getJson('/api/v1/storefront/cart')
            ->assertStatus(200);
    }

    public function test_expired_guest_cart_is_retired_and_replaced(): void
    {
        $product = $this->makeProduct(['price' => 9]);
        [$token, $cart] = $this->freshGuestCart();
        $this->withUnencryptedCookie(CartService::COOKIE, $token)
            ->postJson('/api/v1/storefront/cart/items', ['product_id' => $product->id])->assertStatus(200);

        $cart->forceFill(['expires_at' => now()->subDay()])->save();

        $new = $this->withUnencryptedCookie(CartService::COOKIE, $token)->getJson('/api/v1/storefront/cart');
        $new->assertStatus(200)->assertJsonPath('data.items', []);

        $newCookie = $this->grabCookie($new);
        $this->assertNotNull($newCookie, 'Replacement cart must receive a fresh token cookie');
        $this->assertNotSame($token, $newCookie->getValue());

        $cart->refresh();
        $this->assertSame('expired', $cart->status);
    }

    // ─── A-04: entitlement behavior on the public group ─────────────────

    public function test_unentitled_tenant_cart_api_is_403(): void
    {
        $other = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $other], [
            'name' => 'SF008 Nope', 'email' => 'sf008-nope-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($other);

        $this->getJson('/api/v1/storefront/cart')->assertStatus(403);

        // cart routes carry the gates + throttles (FR-MOD-02, FR-PLAT-05)
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'api/v1/storefront/')) {
                continue;
            }
            $mw = $route->middleware();
            $this->assertContains('entitlement:storefront', $mw, $route->uri());
            $this->assertTrue(
                collect($mw)->contains(fn (string $m) => str_starts_with($m, 'throttle:')),
                $route->uri().' lacks a throttle'
            );
        }
    }

    // ─── Catalog feed shares the renderer's rules ──────────────────────

    public function test_catalog_feed_uses_same_visibility_and_projection_rules(): void
    {
        $this->makeProduct(['name' => 'Feed Mug', 'price' => 3, 'cost' => 8888.88, 'show_on_website' => true]);
        $this->makeProduct(['name' => 'Feed Hidden', 'show_on_website' => false]);

        $response = $this->getJson('/api/v1/storefront/catalog/products?limit=20');
        $response->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Feed Mug'));
        $this->assertFalse($names->contains('Feed Hidden'));

        // projection: no internal fields ever serialize (FR-SF-07)
        $first = $response->json('data.0');
        foreach (['cost', 'vendor_price', 'wholesale_price', 'msrp', 'min_price', 'max_price', 'income_account_id'] as $leak) {
            $this->assertArrayNotHasKey($leak, $first, "public feed leaked internal column {$leak}");
        }
        $this->assertStringNotContainsString('8888.88', $response->getContent());
    }

    // ─── CartService merge (account sessions land next slice) ───────────

    public function test_merge_consolidates_guest_cart_into_account_cart(): void
    {
        $product = $this->makeProduct(['price' => 5]);
        $other = $this->makeProduct(['price' => 6]);

        [$token, $guestCart] = $this->freshGuestCart();
        $carts = app(CartService::class);
        $line = $carts->addOrUpdateItem($guestCart, $product->id, null, 2);
        $carts->addOrUpdateItem($guestCart, $other->id, null, 1);

        $account = StorefrontAccount::create([
            'email' => 'mergee-'.Str::random(6).'@example.test',
            'password' => 'x',
            'status' => 'active',
        ]);

        $merged = $carts->mergeIntoAccount($guestCart->fresh(), $account->id);

        $this->assertSame($account->id, $merged->account_id);
        $this->assertSame('active', $merged->status);

        // quantities consolidated, guest cart consumed
        $quote = $carts->quote($merged);
        $this->assertCount(2, $quote['items']);
        $this->assertSame(3.0, array_sum(array_column($quote['items'], 'quantity')));
        $this->assertSame('ordered', $guestCart->fresh()->status);

        // idempotent re-merge keeps one active cart per account
        $again = $carts->mergeIntoAccount($guestCart->fresh(), $account->id);
        $this->assertSame($merged->id, $again->id);
        $this->assertSame(1, StorefrontCart::query()->where('account_id', $account->id)->where('status', 'active')->count());
    }

    // ─── Query budget ───────────────────────────────────────────────────

    public function test_quote_query_budget_is_bounded(): void
    {
        $p1 = $this->makeProduct(['price' => 2]);
        $p2 = $this->makeProduct(['price' => 3]);
        $v1 = ProductVariant::create(['product_id' => $p1->id, 'sku' => 'QB-'.Str::random(4), 'name' => 'S', 'price' => 4, 'cost' => 1, 'is_active' => true]);

        $cart = StorefrontCart::create(['status' => 'active', 'currency' => 'USD']);
        $carts = app(CartService::class);
        $carts->addOrUpdateItem($cart, $p1->id, null, 1);
        $carts->addOrUpdateItem($cart, $p2->id, null, 2);
        $carts->addOrUpdateItem($cart, $p1->id, $v1->id, 1);

        $cart->unsetRelation('items');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $quote = $carts->quote($cart);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Identity lookups MUST be batched (this is the real N+1 guard):
        // exactly one products query and at most one variants query for the
        // whole cart, never one per line.
        $productsQueries = collect($queries)->filter(
            fn (array $q) => str_contains(strtolower($q['query']), 'from "products"')
        )->count();
        $variantQueries = collect($queries)->filter(
            fn (array $q) => str_contains(strtolower($q['query']), 'from "product_variants"')
        )->count();

        $this->assertSame(1, $productsQueries, 'products must be fetched in ONE batched query');
        $this->assertLessThanOrEqual(2, $variantQueries, 'variant identity must stay batched (one cart query + at most one inside the canonical pricing service)');

        // The remaining per-line queries are the CANONICAL pricing/tax
        // services (Product::getPriceForQuantity / TaxResolutionService)
        // which must not be duplicated for batching's sake (SOP §4). The
        // total stays LINEAR in lines. Bound reflects platform-internal
        // per-call lookups (price tiers, settings, default tax) that have
        // grown over time — the guarded invariant is BATCHED identity
        // (asserted above) + linearity, not a fixed constant. Batch-pricing
        // inside those services is a documented P2 perf item.
        $this->assertLessThanOrEqual(
            6 + 5 * count($quote['items']),
            count($queries),
            'quote queries must stay linear-batched: '.count($queries)
        );
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function grabCookie($response): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $c) {
            if ($c->getName() === CartService::COOKIE) {
                return $c;
            }
        }

        return null;
    }

    /** @return array{0:string,1:StorefrontCart} */
    private function freshGuestCart(): array
    {
        $response = $this->getJson('/api/v1/storefront/cart');
        $response->assertStatus(200);

        $cookie = $this->grabCookie($response);
        $this->assertNotNull($cookie);

        $cartId = $response->json('data.cart_id');

        return [$cookie->getValue(), StorefrontCart::findOrFail($cartId)];
    }

    /** @param  array<string,mixed>  $overrides */
    private function makeProduct(array $overrides): Product
    {
        $name = $overrides['name'] ?? 'Cart Product '.Str::random(4);

        return Product::factory()->create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'show_on_website' => true,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'stock_quantity' => 100,
            'tax_rate' => 0,
        ], $overrides));
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

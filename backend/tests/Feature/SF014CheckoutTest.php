<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\AccountService;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-014 — Idempotent checkout + confirmation tokens (FR-CO-03/05/06/10,
 * acceptance A-12/A-13, FR-ORD-03 entity/customer linkage) over real HTTP.
 */
class SF014CheckoutTest extends TenantTestCase
{
    private string $tenantId;

    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withCredentials();
        DB::statement('SET search_path TO public');

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF014 Tenant', 'email' => 'sf014-'.Str::random(6).'@example.test',
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
        $this->warehouseId = (int) Warehouse::create(['name' => 'Main', 'code' => 'SF014-'.Str::upper(Str::random(4)), 'is_active' => true])->id;

        if (! User::query()->exists()) {
            User::factory()->create();
        }
    }

    // ─── A-12: one submit key -> one order, one reservation set ─────────

    public function test_checkout_places_order_with_server_totals_and_token(): void
    {
        $product = $this->makeProduct(['price' => 25.00, 'tax_rate' => 10]);
        [$guestToken, $cart] = $this->cartWith($product->id, 2);

        $key = 'idem-'.Str::random(24);
        $res = $this->checkout($guestToken, $key, ['product_price_test' => true], [
            'price' => 0.01, 'total' => 0.02, // hostile client fields — ignored
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.totals.subtotal', '50.00')
            ->assertJsonPath('data.totals.tax', '5.00')
            ->assertJsonPath('data.totals.total', '55.00')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.replay', false);

        $this->assertNotNull($res->json('data.confirmation_token'));
        $this->assertStringNotContainsString('0.01', $res->getContent());

        $order = StorefrontOrder::query()->where('idempotency_key', $key)->firstOrFail();
        $this->assertSame(1, StorefrontOrder::query()->where('idempotency_key', $key)->count());
        $this->assertSame('55.00', (string) $order->total);
        $this->assertSame('storefront', $order->channel);
        $this->assertNotNull($order->tax_breakdown);
        $this->assertSame('ordered', $cart->fresh()->status);
        // Token at rest is hashed only.
        $this->assertNotSame($res->json('data.confirmation_token'), $order->guest_token_hash);
        $this->assertSame(hash('sha256', $res->json('data.confirmation_token')), $order->guest_token_hash);
    }

    public function test_replayed_submit_is_idempotent_no_second_order_or_reservation(): void
    {
        $product = $this->makeProduct(['price' => 10, 'track_inventory' => true, 'stock_quantity' => 5]);
        StockLevel::create(['product_id' => $product->id, 'warehouse_id' => $this->warehouseId, 'quantity' => 5, 'reserved_quantity' => 0]);

        [$guestToken, $cart] = $this->cartWith($product->id, 2);
        $key = 'idem-'.Str::random(24);

        $first = $this->checkout($guestToken, $key);
        $first->assertStatus(201);

        // Replay AFTER the cart was consumed (fresh cart state) still hits the
        // idempotent pre-check and returns the SAME order.
        $second = $this->checkout($guestToken, $key);
        $second->assertStatus(200)
            ->assertJsonPath('data.replay', true)
            ->assertJsonPath('data.order_number', $first->json('data.order_number'));
        $this->assertNull($second->json('data.confirmation_token'), 'replay must not re-issue a token');

        $order = StorefrontOrder::query()->where('idempotency_key', $key)->sole();
        $this->assertCount(1, $order->items);
        $this->assertSame(1, StockReservation::query()->where('source_document_type', 'storefront_order')->where('source_document_id', $order->id)->count());
    }

    public function test_reservations_created_for_tracked_lines_at_fulfillment_warehouse(): void
    {
        $product = $this->makeProduct(['price' => 4, 'track_inventory' => true, 'stock_quantity' => 8]);
        StockLevel::create(['product_id' => $product->id, 'warehouse_id' => $this->warehouseId, 'quantity' => 8, 'reserved_quantity' => 0]);

        [$guestToken, ] = $this->cartWith($product->id, 3);
        $res = $this->checkout($guestToken, 'idem-'.Str::random(24));
        $res->assertStatus(201);

        $order = StorefrontOrder::query()->latest('id')->firstOrFail();

        $r = StockReservation::query()
            ->where('source_document_type', 'storefront_order')
            ->where('source_document_id', $order->id)
            ->first();
        $this->assertNotNull($r, 'tracked line must reserve stock');
        $this->assertSame(3, (int) $r->quantity);
        $this->assertSame($this->warehouseId, (int) $r->warehouse_id);
        $this->assertSame($this->warehouseId, (int) $order->fresh()->fulfillment_warehouse_id);
        $this->assertEqualsWithDelta(time() + 30 * 60, $r->expires_at->timestamp, 120);
    }

    public function test_insufficient_stock_aborts_atomically_backorder_policy_overrides(): void
    {
        $product = $this->makeProduct(['price' => 4, 'track_inventory' => true, 'stock_quantity' => 10]);
        $level = StockLevel::create(['product_id' => $product->id, 'warehouse_id' => $this->warehouseId, 'quantity' => 10, 'reserved_quantity' => 0]);

        [$guestToken, $cart] = $this->cartWith($product->id, 5);

        // Stock drains between cart-add and checkout (the A-13 race shape).
        $level->update(['quantity' => 1]);
        $product->forceFill(['stock_quantity' => 1])->saveQuietly();
        $key = 'idem-'.Str::random(24);

        $before = StorefrontOrder::query()->count();
        $this->checkout($guestToken, $key)->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame($before, StorefrontOrder::query()->count(), 'no orphan order');
        $this->assertSame('active', $cart->fresh()->status, 'cart preserved for retry');

        // Tenant policy flip -> order placed, no reservation.
        StorefrontSettings::current()->update(['allow_backorder' => true]);
        $this->checkout($guestToken, 'idem-'.Str::random(24))->assertStatus(201);
        $this->assertSame(0, StockReservation::query()->where('source_document_type', 'storefront_order')->count());
    }

    public function test_guest_policy_and_empty_cart_and_validation(): void
    {
        StorefrontSettings::current()->update(['guest_checkout' => false]);
        $product = $this->makeProduct(['price' => 3]);
        [$guestToken, ] = $this->cartWith($product->id, 1);
        $this->checkout($guestToken, 'idem-'.Str::random(24))
            ->assertStatus(422)->assertJsonPath('code', 'guest_checkout_disabled');
        StorefrontSettings::current()->update(['guest_checkout' => true]);

        // empty cart (fresh guest cart with only a token, no lines)
        $tok = bin2hex(random_bytes(32));
        StorefrontCart::create(['guest_token_hash' => hash('sha256', $tok), 'status' => 'active']);
        $this->checkout($tok, 'idem-'.Str::random(24))
            ->assertStatus(422)->assertJsonPath('code', 'cart_empty');

        // missing idempotency handling (validator rejects short key)
        $this->checkout($guestToken, 'short')
            ->assertStatus(422);

        // missing address parts
        $res = $this->postJson('/api/v1/storefront/checkout', [
            'email' => 'x@example.test', 'idempotency_key' => 'idem-'.Str::random(24),
            'shipping_address' => ['name' => 'x'],
        ]);
        $res->assertStatus(422)->assertJsonValidationErrors(['shipping_address.address1', 'shipping_address.city', 'shipping_address.postal_code', 'shipping_address.country_code']);
    }

    public function test_confirmation_token_lookup_and_wrong_token(): void
    {
        $product = $this->makeProduct(['price' => 6]);
        [$guestToken, ] = $this->cartWith($product->id, 1);
        $res = $this->checkout($guestToken, 'idem-'.Str::random(24));
        $token = $res->json('data.confirmation_token');

        $lookup = $this->getJson('/api/v1/storefront/orders/confirm/'.$token);
        $lookup->assertStatus(200)
            ->assertJsonPath('data.order_number', $res->json('data.order_number'))
            ->assertJsonPath('data.items.0.name', $product->name);

        $this->getJson('/api/v1/storefront/orders/confirm/'.bin2hex(random_bytes(16)))->assertStatus(404);
        // garbage / hostile shapes rejected before hitting the DB layer
        $this->getJson('/api/v1/storefront/orders/confirm/' . htmlspecialchars('../../etc/passwd'))->assertStatus(404);
    }

    public function test_account_checkout_links_identity_and_requires_csrf(): void
    {
        $svc = app(AccountService::class);
        ['account' => $account] = $svc->register('buyer-'.Str::random(6).'@example.test', 'Passw0rd!');
        $auth = $svc->login($account->email, 'Passw0rd!');

        $product = $this->makeProduct(['price' => 9]);
        $cart = app(CartService::class)->activeAccountCart((int) $account->id);
        app(CartService::class)->addOrUpdateItem($cart, $product->id, null, 1);

        $body = [
            'email' => $account->email,
            'idempotency_key' => 'idem-'.Str::random(24),
            'shipping_address' => ['name' => 'A', 'address1' => 'B', 'city' => 'C', 'postal_code' => 'D', 'country_code' => 'US'],
        ];

        // session present without CSRF echo -> 419
        $noCsrf = $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $auth['session_token'])
            ->postJson('/api/v1/storefront/checkout', $body);
        fwrite(STDERR, "\nCSRFTEST status=".$noCsrf->status()." body=".substr($noCsrf->getContent(), 0, 300).PHP_EOL);
        $noCsrf->assertStatus(419);

        // proper session + CSRF pair
        $res = $this->withUnencryptedCookies([
            AccountService::SESSION_COOKIE => $auth['session_token'],
            AccountService::CSRF_COOKIE => $auth['csrf_token'],
        ])->withHeaders(['X-CSRF-Token' => $auth['csrf_token']])->postJson('/api/v1/storefront/checkout', $body);

        $res->assertStatus(201);
        $order = StorefrontOrder::query()->where('idempotency_key', $body['idempotency_key'])->firstOrFail();
        $this->assertSame($account->id, (int) $order->account_id);
        $this->assertNotNull($order->customer_id, 'canonical CRM customer linked');
        $this->assertSame($account->customer_id, (int) $order->customer_id);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /** @return array{0:string,1:StorefrontCart} */
    private function cartWith(int $productId, int $qty): array
    {
        $token = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $token), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, $productId, null, $qty);

        return [$token, $cart];
    }

    /** @param  array<string,mixed>  $bodyOverrides */
    private function checkout(?string $guestToken, string $key, array $bodyOverrides = [], array $merge = [])
    {
        $body = array_merge([
            'email' => 'shopper-'.Str::random(6).'@example.test',
            'idempotency_key' => $key,
            'shipping_address' => [
                'name' => 'Shop Per', 'address1' => '1 High St', 'city' => 'Springfield',
                'postal_code' => '12345', 'country_code' => 'US',
            ],
        ], $bodyOverrides, $merge);

        $http = $guestToken !== null
            ? $this->withUnencryptedCookie(CartService::COOKIE, $guestToken)
            : $this;

        return $http->postJson('/api/v1/storefront/checkout', $body);
    }

    private function makeProduct(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'SF14 Product '.Str::random(4);

        return Product::factory()->create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name),
            'show_on_website' => true,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'stock_quantity' => 100,
            'price' => 10,
            'tax_rate' => 0,
        ], $overrides));
    }
}

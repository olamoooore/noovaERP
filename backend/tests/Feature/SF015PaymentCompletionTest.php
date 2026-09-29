<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-015 — Canonical completion for storefront_order payments
 * (FR-PAY-02/04, FR-ORD-02, PAY-R6/R8). Drives the REAL
 * PaymentTransactionCompletionService branch used by webhooks and
 * confirm-payment. Invoice/JE conversion is the follow-up slice by design;
 * these tests pin the money-safety invariants: amount binding, at-most-once
 * stock issue, machine-only state, replay convergence, fail-closed handler
 * absence, and legacy-path regression.
 */
class SF015PaymentCompletionTest extends TenantTestCase
{
    private string $tenantId;

    private int $warehouseId;

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
            'name' => 'SF015 Tenant', 'email' => 'sf015-'.Str::random(6).'@example.test',
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
        $this->warehouseId = (int) Warehouse::create(['name' => 'SF015 WH', 'code' => 'S15'.Str::upper(Str::random(3)), 'is_active' => true])->id;

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        // Posting authority actor: the machine-posting resolves the first
        // tenant user; give it OWNER membership (Spatie roles + central
        // company access) so the GL gate evaluates a real grant.
        if (! User::query()->exists()) {
            $this->bindTenantContext($this->tenantId);
            $u = User::factory()->create();
            DB::table('public.users')->updateOrInsert(
                ['email' => $u->email],
                ['name' => $u->name, 'password' => $u->password, 'created_at' => now(), 'updated_at' => now()]
            );
            $centralId = DB::table('public.users')->where('email', $u->email)->value('id');
            DB::table('user_company_access')->updateOrInsert(
                ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
                ['role' => 'owner', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
            );
            $u->assignRole('Owner');
        }
    }

    public function test_completion_pays_order_issues_stock_once_and_replays_cleanly(): void
    {
        $product = $this->makeProduct(['price' => 10.00, 'track_inventory' => true, 'stock_quantity' => 10]);
        $level = StockLevel::create(['product_id' => $product->id, 'warehouse_id' => $this->warehouseId, 'quantity' => 10, 'reserved_quantity' => 0]);

        [$guestToken, $cart] = $this->cartWith($product->id, 3);
        $key = 'idem-'.Str::random(24);
        $this->checkout($guestToken, $key)->assertStatus(201);
        $order = StorefrontOrder::query()->where('idempotency_key', $key)->sole();

        $this->assertSame('open', StockReservation::query()->where('source_document_id', $order->id)->sole()->status);

        $transaction = $this->makeTransaction($order, (float) $order->total);

        app(PaymentTransactionCompletionService::class)->complete($transaction, 'card');

        $order->refresh();
        $this->assertSame(OrderStatus::PAID->value, $order->status);
        $this->assertSame(PaymentStatus::PAID->value, $order->payment_status);
        $this->assertNotNull($order->paid_at);

        $hist = $order->statusHistory()->get();
        $this->assertSame(2, $hist->count());
        $this->assertTrue($hist->every(fn ($h) => $h->actor_type === 'gateway'));

        // Stock issued exactly once; the checkout reservation is fulfilled.
        // NOTE: the SalesOrder lifecycle legitimately RE-RESERVES for the
        // SO document (platform behaviour, SalesOrderService L962), so the
        // counter invariant is reserved == sum(ACTIVE reservations).
        $level->refresh();
        $this->assertSame(7.0, (float) $level->quantity);
        $sfRes = StockReservation::query()->where('source_document_type', 'storefront_order')->sole();
        $this->assertSame('fulfilled', $sfRes->status);
        $activeSum = (float) StockReservation::query()
            ->where('product_id', $product->id)
            ->whereIn('status', ['open', 'allocated'])
            ->selectRaw('COALESCE(SUM(quantity - quantity_fulfilled),0) as agg')->value('agg');
        $this->assertSame((float) $level->reserved_quantity, $activeSum);
        $this->assertSame(1, StockMovement::query()->where('reference_type', 'storefront_order')->where('reference_id', $order->id)->count());
        $this->assertSame('fulfilled', StockReservation::query()->where('source_document_type', 'storefront_order')->where('source_document_id', $order->id)->sole()->status);

        $this->assertSame(1, $order->paymentAttempts()->count());
        $transaction->refresh();
        $this->assertNotNull($transaction->processed_at);
        $this->assertNotNull($transaction->customer_id);

        // ── REPLAY: no double anything ──
        app(PaymentTransactionCompletionService::class)->complete($transaction->fresh(), 'card');

        $this->assertSame(2, $order->fresh()->statusHistory()->count(), 'replay must not add history');
        $this->assertSame(1, $order->fresh()->paymentAttempts()->count());
        $this->assertSame(1, StockMovement::query()->where('reference_type', 'storefront_order')->where('reference_id', $order->id)->count());
        $level->refresh();
        $this->assertSame(7.0, (float) $level->quantity, 'replay must not double-deduct');
        $this->assertSame(1, \App\Models\Payment::query()->where('reference', $transaction->transaction_id)->count(), 'replay must not double-settle');
        $this->assertSame(1, \App\Models\JournalEntryLink::query()->where('source_document_type', 'storefront_order')->where('source_document_id', $order->id)->count(), 'replay must not double-post');
    }

    public function test_amount_mismatch_is_rejected_without_side_effects(): void
    {
        $product = $this->makeProduct(['price' => 10.00, 'track_inventory' => true, 'stock_quantity' => 10]);
        StockLevel::create(['product_id' => $product->id, 'warehouse_id' => $this->warehouseId, 'quantity' => 10, 'reserved_quantity' => 0]);

        [$guestToken] = $this->cartWith($product->id, 3);
        $key = 'idem-'.Str::random(24);
        $this->checkout($guestToken, $key)->assertStatus(201);
        $order = StorefrontOrder::query()->where('idempotency_key', $key)->sole();

        $transaction = $this->makeTransaction($order, 25.00); // short-paid

        try {
            app(PaymentTransactionCompletionService::class)->complete($transaction, 'card');
            $this->fail('amount mismatch must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('amount mismatch', $e->getMessage());
        }

        $order->refresh();
        $this->assertSame(OrderStatus::PENDING->value, $order->status);
        $this->assertSame(PaymentStatus::PENDING_PAYMENT->value, $order->payment_status);
        $this->assertSame(0, $order->paymentAttempts()->count());
        $this->assertNull($transaction->fresh()->processed_at);
        $this->assertSame(10.0, (float) StockLevel::query()->where('product_id', $product->id)->sole()->quantity);
    }

    public function test_storefront_transaction_fails_closed_without_handler(): void
    {
        $product = $this->makeProduct(['price' => 5]);
        [$guestToken] = $this->cartWith($product->id, 1);
        $key = 'idem-'.Str::random(24);
        $this->checkout($guestToken, $key)->assertStatus(201);
        $order = StorefrontOrder::query()->where('idempotency_key', $key)->sole();
        $transaction = $this->makeTransaction($order, (float) $order->total);

        // Without the module-provided handler the transaction must NOT be
        // mis-resolved against a legacy web order sharing the id space.
        $contract = \App\Contracts\StorefrontOrderPaymentCompletion::class;
        app()->offsetUnset($contract);

        try {
            app(PaymentTransactionCompletionService::class)->complete($transaction, 'card');
            $this->fail('missing handler must fail closed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('handler is unavailable', $e->getMessage());
        }

        $this->assertSame(OrderStatus::PENDING->value, $order->fresh()->status);

        app()->singleton($contract, \Modules\Storefront\Services\StorefrontPaymentCompletionService::class);
        app(PaymentTransactionCompletionService::class)->complete($transaction->fresh(), 'card');
        $this->assertSame(OrderStatus::PAID->value, $order->fresh()->status);
    }

    public function test_bundle_lines_are_blocked_at_checkout(): void
    {
        $product = $this->makeProduct(['price' => 8]);
        $product->forceFill(['is_bundle' => true])->save();

        [$guestToken] = $this->cartWith($product->id, 1);
        $this->checkout($guestToken, 'idem-'.Str::random(24))
            ->assertStatus(422)
            ->assertJsonPath('code', 'bundle_unsupported');

        $this->assertSame(0, StorefrontOrder::query()->count(), 'no order may exist for blocked bundles');
    }

    public function test_legacy_web_order_completion_path_still_resolves_by_order_id(): void
    {
        // Branch-order regression: metadata type selects the LEGACY resolver
        // (its error text, not the storefront handler).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('web order');

        $gateway = PaymentGateway::create(['gateway_name' => 'stripe', 'is_active' => true, 'is_test_mode' => true]);
        $fresh = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'pi_legacy_'.Str::random(8),
            'amount' => 9, 'currency' => 'USD', 'status' => 'pending',
            'metadata' => ['type' => 'web_order', 'order_id' => 999999],
        ]);

        app(PaymentTransactionCompletionService::class)->complete($fresh, 'card');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function makeTransaction(StorefrontOrder $order, float $amount): PaymentTransaction
    {
        $gateway = PaymentGateway::query()->where('gateway_name', 'stripe')->first()
            ?? PaymentGateway::create(['gateway_name' => 'stripe', 'is_active' => true, 'is_test_mode' => true]);

        return PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'pi_sf15_'.Str::random(10),
            'amount' => $amount,
            'currency' => $order->currency ?? 'USD',
            'status' => 'pending',
            'metadata' => ['type' => 'storefront_order', 'order_id' => (int) $order->id],
        ]);
    }

    private function cartWith(int $productId, int $qty): array
    {
        $token = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $token), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, $productId, null, $qty);

        return [$token, $cart];
    }

    private function checkout(string $guestToken, string $key)
    {
        return $this->withUnencryptedCookie(CartService::COOKIE, $guestToken)->postJson('/api/v1/storefront/checkout', [
            'email' => 'sf15-'.Str::random(6).'@example.test',
            'idempotency_key' => $key,
            'shipping_address' => ['name' => 'S F', 'address1' => '15 Rd', 'city' => 'Town', 'postal_code' => '54321', 'country_code' => 'SG'],
        ]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'SF15 Product '.Str::random(4);

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

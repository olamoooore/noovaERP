<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-026 — FR-ORD-05 partial fulfilment + tracking (P3).
 *
 * The shipment entry is a LOGISTICS ledger: stock was issued once at payment
 * (SF015), so fulfilment must NEVER move inventory — it advances
 * fulfilled_quantity, appends tracking numbers, and walks the ONE shared
 * machine (paid → processing; tracking ⇒ dispatched → shipped + mail).
 * Quantity guards and customer surfacing (guest ?sfconf= + account order
 * detail) are pinned exactly.
 */
class SF026PartialFulfilmentTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Queue::fake();

        DB::statement('SET search_path TO public');
        $this->bindTenantContext((string) Str::uuid());
        $this->seed(RolePermissionSeeder::class);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF026 Shop', 'email' => 'sf026-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->create([
            'tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id,
            'is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now(),
        ]);
        app(FeatureGateService::class)->invalidate($this->tenantId);

        StorefrontSettings::current()->update(['currency_code' => 'SGD']);

        $owner = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $owner->email],
            ['name' => $owner->name, 'password' => $owner->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $owner->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'owner', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $owner->assignRole('Owner');
        $this->bindTenantContext($this->tenantId);

        $this->artisan('storefront:templates:seed')->assertSuccessful();
    }

    public function test_partial_then_tracked_shipment_walks_the_machine_and_never_moves_stock(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        [$order, $token] = $this->paidOrder();
        $line = $order->items()->sole();
        $this->assertSame(2.0, (float) $line->quantity);

        $movementsAtPayment = StockMovement::query()->count();

        // packed, no tracking: paid -> processing
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/fulfil", [
            'lines' => [['line_id' => (int) $line->id, 'quantity' => 1]],
            'note' => 'first box packed',
        ])->assertStatus(200)
            ->assertJsonPath('data.status', OrderStatus::PROCESSING->value)
            ->assertJsonPath('data.remaining', 1);

        // dispatched with tracking: processing -> shipped (+ mail)
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/fulfil", [
            'lines' => [['line_id' => (int) $line->id, 'quantity' => 1]],
            'tracking_number' => 'NinjaVan-952244',
        ])->assertStatus(200)
            ->assertJsonPath('data.status', OrderStatus::SHIPPED->value)
            ->assertJsonPath('data.all_fulfilled', true)
            ->assertJsonPath('data.tracking.0', 'NinjaVan-952244');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::SHIPPED->value, $fresh->status);
        $this->assertSame(2.0, (float) $fresh->items()->sole()->fulfilled_quantity);
        $this->assertSame(['NinjaVan-952244'], $fresh->tracking_numbers);

        // the logistics-only contract: ZERO new stock movements since payment
        $this->assertSame($movementsAtPayment, StockMovement::query()->count(),
            'partial fulfilment must never touch inventory (issued once at payment)');

        // machine-audited transitions exist
        $toStatuses = $fresh->statusHistory()->pluck('to_status')->all();
        $this->assertContains(OrderStatus::PROCESSING->value, $toStatuses);
        $this->assertContains(OrderStatus::SHIPPED->value, $toStatuses);

        // shipped mail through the seeded template, tracking resolved
        $msg = CommunicationMessage::query()->where('subtype', 'storefront.order.shipped')->sole();
        $this->assertSame("Your order {$fresh->order_number} is on its way", $msg->subject);
        $this->assertStringNotContainsString('{{', $msg->body);
        $this->assertStringContainsString('NinjaVan-952244', $msg->body);

        // surfaced to the customer on the guest confirmation link (FR-ORD-05)
        $conf = $this->getJson("/api/v1/storefront/orders/confirm/{$token}")->assertStatus(200);
        $this->assertSame(OrderStatus::SHIPPED->value, $conf->json('data.status'));
        $this->assertSame(['NinjaVan-952244'], $conf->json('data.tracking_numbers'));
        $this->assertEquals(2.0, $conf->json('data.items.0.fulfilled_quantity'));
    }

    public function test_guards_reject_impossible_shipments(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        [$order,] = $this->paidOrder();
        $line = $order->items()->sole();

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/fulfil", [
            'lines' => [['line_id' => (int) $line->id, 'quantity' => 3]],
        ])->assertStatus(422)->assertJsonPath('code', 'quantity_exceeds');

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/fulfil", [
            'lines' => [['line_id' => 999999, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'line_not_found');

        // empty selection: rejected by the request validator (min:1); the
        // service also guards defensively behind it
        $empty = $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/fulfil", [
            'lines' => [],
        ])->assertStatus(422);
        $this->assertArrayHasKey('lines', $empty->json('errors') ?? []);

        $this->assertSame(0.0, (float) $order->fresh()->items()->sole()->fulfilled_quantity,
            'rejected calls must leave no partial state');

        // unpaid order is not fulfillable
        $pending = $this->placeOrder();
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$pending->id}/fulfil", [
            'lines' => [['line_id' => (int) $pending->items()->sole()->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'order_not_fulfillable');
    }

    public function test_tracking_is_append_deduped_and_second_partial_allowed(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        [$order,] = $this->paidOrder();
        $line = $order->items()->sole();

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/fulfil", [
            'lines' => [['line_id' => (int) $line->id, 'quantity' => 0.5]], 'tracking_number' => 'A-1',
        ])->assertStatus(200)->assertJsonPath('data.status', OrderStatus::SHIPPED->value);

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/fulfil", [
            'lines' => [['line_id' => (int) $line->id, 'quantity' => 0.5]], 'tracking_number' => 'A-1',
        ])->assertStatus(200)->assertJsonPath('data.status', OrderStatus::SHIPPED->value);

        $this->assertSame(['A-1'], $order->fresh()->tracking_numbers, 'duplicate tracking numbers collapse');
        $this->assertSame(1.0, (float) $order->fresh()->items()->sole()->fulfilled_quantity);
    }

    public function test_shipped_mail_respects_merchant_toggle_and_rbac(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        $viewer = $this->makeStaff('viewer', 'Viewer');

        StorefrontSettings::current()->update(['notification_settings' => ['order_shipped' => ['email' => false]]]);

        [$order,] = $this->paidOrder();
        $line = $order->items()->sole();

        $this->actingAs($viewer)->postJson("/api/v1/admin/storefront/orders/{$order->id}/fulfil", [
            'lines' => [['line_id' => (int) $line->id, 'quantity' => 1]],
        ])->assertStatus(403)->assertJsonPath('required_permission', 'storefront.write');

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/fulfil", [
            'lines' => [['line_id' => (int) $line->id, 'quantity' => 2]], 'tracking_number' => 'T-77',
        ])->assertStatus(200)->assertJsonPath('data.status', OrderStatus::SHIPPED->value);

        $this->assertSame(0, CommunicationMessage::query()->where('subtype', 'storefront.order.shipped')->count(),
            'FR-MSG-06 toggle suppresses shipped mail while the shipment still records');
        $this->assertSame(2.0, (float) $order->fresh()->items()->sole()->fulfilled_quantity);
    }

    // ─── fixtures ─────────────────────────────────────────────────────

    /** @return array{0: StorefrontOrder, 1: string} */
    private function paidOrder(): array
    {
        $order = $this->placeOrder(qty: 2);

        $gateway = PaymentGateway::query()->where('gateway_name', 'hitpay')->first()
            ?? PaymentGateway::create(['gateway_name' => 'hitpay', 'is_active' => true, 'is_test_mode' => true]);

        $txn = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'sf26pay_'.Str::random(10),
            'amount' => (float) $order->total,
            'currency' => $order->currency,
            'status' => 'completed',
            'metadata' => ['type' => 'storefront_order', 'order_id' => (int) $order->id],
        ]);
        app(PaymentTransactionCompletionService::class)->complete($txn, 'card');

        return [$order->fresh(), (string) $this->lastConfirmToken];
    }

    private ?string $lastConfirmToken = null;

    private function placeOrder(int $qty = 1): StorefrontOrder
    {
        $product = Product::query()->where('name', 'SF26 Widget')->first()
            ?? Product::factory()->create([
                'name' => 'SF26 Widget',
                'slug' => 'sf26-widget',
                'show_on_website' => true,
                'is_active' => true,
                'status' => 'active',
                'track_inventory' => false,
                'stock_quantity' => 50,
                'price' => 25,
                'tax_rate' => 0,
            ]);

        $guestToken = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $guestToken), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, $product->id, null, $qty);

        $res = $this->withUnencryptedCookie(CartService::COOKIE, $guestToken)->postJson('/api/v1/storefront/checkout', [
            'email' => 'sf26-'.Str::random(6).'@example.test',
            'idempotency_key' => 'sf26-'.Str::random(24),
            'shipping_address' => ['name' => 'F M', 'address1' => '26 Rd', 'city' => 'Town', 'postal_code' => '54321', 'country_code' => 'SG'],
        ]);
        $res->assertStatus(201);
        $this->lastConfirmToken = (string) $res->json('data.confirmation_token');

        return StorefrontOrder::query()->findOrFail((int) $res->json('data.id'));
    }

    private function makeStaff(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create(['name' => $spatieRole]);
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);
        $this->bindTenantContext($this->tenantId);

        return $user;
    }
}

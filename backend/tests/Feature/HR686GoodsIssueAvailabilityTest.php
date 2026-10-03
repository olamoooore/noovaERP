<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #686 [SALES-INV] P2 (IN6) — the negative-stock guard compared the
 * requested quantity with the ON-HAND quantity only. Stock held by another
 * confirmed order's reservation was invisible to it, so posting an invoice or
 * completing a delivery order for customer A could ship goods reserved for
 * customer B, leaving B's hold unbacked.
 *
 * Every test below drives the REAL routes (router → middleware → controller)
 * and asserts the issue's business rule: a sales-document goods issue checks
 * available = on hand − reservations of OTHER documents (its own reservation
 * counts as available to it) and the delivery path consumes its own hold.
 * The tenant-level override (allow_negative_stock, permissioned by
 * `inventory.settings` on the settings route) keeps the historical behaviour
 * and is logged.
 */
class HR686GoodsIssueAvailabilityTest extends TenantTestCase
{
    private const OPENING_STOCK = 10;

    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Goods Issue Availability Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        // Posting and cancelling need an open accounting period.
        FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->admin->id,
            ]
        );

        $this->customer = Customer::factory()->create(['payment_terms' => 30]);

        $this->warehouse = Warehouse::create([
            'name' => 'Availability Warehouse',
            'code' => 'AVW-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Availability Widget',
            'sku' => 'AVW-'.uniqid(),
            'track_inventory' => true,
            'cost' => 10,
            'price' => 25,
            'stock_quantity' => 0,
        ]);

        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => self::OPENING_STOCK,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        // The tests assert the guard, not the tenant override — keep the
        // setting explicitly off so the fixture cannot inherit a default.
        \Modules\Inventory\Models\InventorySettings::current()->update(['allow_negative_stock' => false]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fixtures / helpers
    // ─────────────────────────────────────────────────────────────────────

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key,
            'is_entitled' => $isEntitled,
            'source' => $isEntitled ? 'tier_included' : null,
            'name' => strtoupper($key),
        ]);

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    private function apiPost(string $uri, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson($uri, $payload);
    }

    private function userWithRole(string $spatieRole, string $centralRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole);
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $centralRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * Create + confirm a sales order. The warehouse is stamped between create
     * and confirm because POST /sales/orders does not expose warehouse_id, and
     * confirmation is what creates the stock reservation.
     */
    private function confirmedSalesOrder(float $quantity): int
    {
        $response = $this->apiPost('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $quantity,
                'unit_price' => 25,
                'tax_rate' => 0,
            ]],
        ]);
        $response->assertCreated();

        $orderId = (int) $response->json('id');

        \Modules\Sales\Models\SalesOrder::whereKey($orderId)
            ->update(['warehouse_id' => $this->warehouse->id]);

        $this->apiPost("/api/v1/sales/orders/{$orderId}/confirm")->assertOk();

        return $orderId;
    }

    private function invoiceFromSalesOrder(int $orderId): int
    {
        $response = $this->apiPost("/api/v1/sales/orders/{$orderId}/invoice");
        $response->assertCreated();

        return (int) $response->json('invoice.id');
    }

    /** A direct invoice-form invoice (creates its own backing sales order). */
    private function directInvoice(float $quantity): int
    {
        $response = $this->apiPost('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $quantity,
                'unit_price' => 25,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        return (int) $response->json('id');
    }

    private function postInvoice(int $invoiceId): void
    {
        $this->apiPost("/api/v1/invoices/{$invoiceId}/post")->assertOk();
    }

    private function deliveryOrderFor(int $orderId, float $quantity): int
    {
        $order = \Modules\Sales\Models\SalesOrder::with('lines')->findOrFail($orderId);

        $response = $this->apiPost('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $orderId,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'sales_order_line_id' => $order->lines->first()->id,
                'quantity_delivered' => $quantity,
            ]],
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    /** A standalone delivery order (no sales order, so no own reservation). */
    private function standaloneDeliveryOrder(float $quantity): int
    {
        $response = $this->apiPost('/api/v1/sales/delivery-orders', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity_delivered' => $quantity,
                'unit_price' => 25,
            ]],
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function confirmDeliveryOrder(int $deliveryOrderId): void
    {
        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/confirm")->assertOk();
    }

    private function completeDeliveryOrder(int $deliveryOrderId): \Illuminate\Testing\TestResponse
    {
        return $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete");
    }

    private function stockOnHand(): float
    {
        return (float) StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
    }

    private function reservedOnHand(): float
    {
        return (float) StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('reserved_quantity');
    }

    private function stockOut(): float
    {
        return (float) StockMovement::where('type', 'sale')
            ->where('product_id', $this->product->id)
            ->sum('quantity');
    }

    private function salesOrderReservation(int $orderId): ?StockReservation
    {
        return StockReservation::where('source_document_type', 'SalesOrder')
            ->where('source_document_id', $orderId)
            ->first();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The bug: another order's reservation must be treated as unavailable
    // ═══════════════════════════════════════════════════════════════════════

    public function test_invoice_posting_refuses_to_ship_stock_reserved_for_another_order(): void
    {
        // Order B holds 8 of the 10 units. Only 2 are genuinely available.
        $reservingOrderId = $this->confirmedSalesOrder(8);
        $this->assertSame(8.0, $this->reservedOnHand(), 'Fixture: order B reserved 8 units');
        $this->assertNotNull($this->salesOrderReservation($reservingOrderId));

        // Customer A's direct invoice cannot reserve the 5 units it needs
        // (only 2 available), so its backing order proceeds as a backorder.
        $invoiceId = $this->directInvoice(5);
        $this->assertSame(0.0, (float) StockReservation::where('product_id', $this->product->id)
            ->where('source_document_id', '!=', $reservingOrderId)
            ->sum('quantity'), 'Fixture: customer A holds no reservation');

        $response = $this->apiPost("/api/v1/invoices/{$invoiceId}/post");

        $this->assertSame(
            422,
            $response->status(),
            'Posting must be refused: 5 units would consume stock reserved for order B. Got: '.$response->getContent()
        );
        $this->assertSame('draft', Invoice::query()->findOrFail($invoiceId)->status, 'The refused invoice stays draft');
        $this->assertSame(0.0, $this->stockOut(), 'No stock may leave the warehouse');
        $this->assertSame((float) self::OPENING_STOCK, $this->stockOnHand(), 'On-hand stock is untouched');
        $this->assertSame(8.0, $this->reservedOnHand(), 'Order B keeps its full reservation');
    }

    public function test_delivery_order_completion_refuses_to_ship_stock_reserved_for_another_order(): void
    {
        // Customer A's standalone delivery order is created first: no holds
        // exist yet, so #465's creation check (on hand − reservations) passes
        // on the full 10 units.
        $deliveryOrderId = $this->standaloneDeliveryOrder(5);
        $this->confirmDeliveryOrder($deliveryOrderId);

        // Order B now holds 8 of the 10 units. Completing customer A's order
        // must still respect that hold even though it already passed creation.
        $reservingOrderId = $this->confirmedSalesOrder(8);
        $this->assertSame(8.0, $this->reservedOnHand(), 'Fixture: order B reserved 8 units');

        $response = $this->completeDeliveryOrder($deliveryOrderId);

        $this->assertSame(
            422,
            $response->status(),
            'Completing the DO must be refused: 5 units would consume stock reserved for order B. Got: '.$response->getContent()
        );
        $this->assertSame(
            'confirmed',
            \Modules\Sales\Models\DeliveryOrder::query()->findOrFail($deliveryOrderId)->status,
            'A refused completion leaves the delivery order confirmed'
        );
        $this->assertSame(0.0, $this->stockOut(), 'No stock may leave the warehouse');
        $this->assertSame((float) self::OPENING_STOCK, $this->stockOnHand(), 'On-hand stock is untouched');
        $this->assertSame(8.0, $this->reservedOnHand(), 'Order B keeps its full reservation');
        $this->assertNotNull($this->salesOrderReservation($reservingOrderId));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The document's OWN reservation counts as available to it
    // ═══════════════════════════════════════════════════════════════════════

    public function test_invoice_can_ship_the_stock_its_own_order_reserved(): void
    {
        // Only 5 units on hand; order A's confirmation holds all of them, so
        // 0 units are available to anyone else.
        StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['quantity' => 5]);

        $orderId = $this->confirmedSalesOrder(5);
        $this->assertSame(5.0, $this->reservedOnHand(), 'Fixture: order A holds all 5 units');

        $invoiceId = $this->invoiceFromSalesOrder($orderId);
        $this->postInvoice($invoiceId);

        $this->assertSame('posted', Invoice::query()->findOrFail($invoiceId)->status,
            'An order may always ship the stock it reserved itself');
        $this->assertSame(0.0, $this->stockOnHand());
        $this->assertSame(5.0, $this->stockOut());
    }

    public function test_delivery_order_completion_consumes_its_own_reservation(): void
    {
        $orderId = $this->confirmedSalesOrder(self::OPENING_STOCK);
        $this->assertSame(10.0, $this->reservedOnHand());

        $deliveryOrderId = $this->deliveryOrderFor($orderId, 10);
        $this->completeDeliveryOrder($deliveryOrderId)->assertOk();

        $this->assertSame(0.0, $this->stockOnHand());
        $this->assertSame(10.0, $this->stockOut());
        $this->assertSame(0.0, $this->reservedOnHand(), 'The delivery consumed its own reservation');

        $reservation = $this->salesOrderReservation($orderId);
        $this->assertNotNull($reservation);
        $this->assertSame(10, (int) $reservation->quantity_fulfilled);
        $this->assertNotContains($reservation->status, ['open', 'allocated']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The tenant override: permissioned and logged
    // ═══════════════════════════════════════════════════════════════════════

    public function test_allow_negative_stock_override_is_permissioned_logged_and_keeps_existing_behaviour(): void
    {
        $this->confirmedSalesOrder(8);
        $invoiceId = $this->directInvoice(5);

        // Without the override the issue is refused.
        $this->apiPost("/api/v1/invoices/{$invoiceId}/post")->assertStatus(422);

        Log::spy();

        // A Manager may do inventory/sales work but may not switch the stock
        // override on — that is the seeded `inventory.settings` authority.
        $manager = $this->userWithRole('Manager', 'manager');
        $this->actingAs($manager)
            ->putJson('/api/v1/inventory/settings', ['allow_negative_stock' => true])
            ->assertForbidden();

        // Owner/Admin hold `inventory.settings`.
        $this->actingAs($this->admin)
            ->putJson('/api/v1/inventory/settings', ['allow_negative_stock' => true])
            ->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'allow_negative_stock'))
            ->atLeast()->once();

        // With the override on, the historical behaviour is kept: the issue
        // proceeds, and the override that consumed reserved stock is logged.
        $this->postInvoice($invoiceId);

        $this->assertSame('posted', Invoice::query()->findOrFail($invoiceId)->status);
        $this->assertSame(5.0, $this->stockOnHand(), 'The override lets the issue go through, as before');
        $this->assertSame(5.0, $this->stockOut());
        $this->assertSame(8.0, $this->reservedOnHand(), 'The override consumed stock reserved for order B');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'reserved by other documents'))
            ->atLeast()->once();

        // The override is also written to the queryable audit path.
        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'stock_availability_override',
            'reference_type' => 'Invoice',
            'reference_id' => $invoiceId,
        ]);
    }
}

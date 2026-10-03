<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Modules\Sales\Models\DeliveryOrder;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #465 [SALES-DO] P2 (DO13) — standalone delivery-order creation
 * compared the requested quantity with the ON-HAND quantity only
 * (getStockLevel()->quantity). Stock already reserved by a confirmed sales
 * order was invisible, so goods another customer's order was holding could
 * be shipped to someone else.
 *
 * The tests drive the REAL routes (router → middleware → controller):
 *  - creation must compare against available = on hand − active reservations
 *    held by other documents (a standalone DO holds none of its own), and
 *  - the create page's stock-check endpoint must report on_hand, reserved
 *    and available so the user sees the hold before submitting.
 *
 * The #686 availability formula (InventoryService) is the single source of
 * truth for both.
 */
class HR465StandaloneDeliveryOrderAvailabilityTest extends TenantTestCase
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
            'name' => 'Standalone DO Availability Tenant',
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

        $this->customer = Customer::factory()->create(['payment_terms' => 30]);

        $this->warehouse = Warehouse::create([
            'name' => 'Standalone DO Warehouse',
            'code' => 'SDO-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Standalone DO Widget',
            'sku' => 'SDO-'.uniqid(),
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

        \Modules\Inventory\Models\InventorySettings::current()->update(['allow_negative_stock' => false]);
    }

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

    private function standaloneDeliveryOrderPayload(float $quantity): array
    {
        return [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity_delivered' => $quantity,
                'unit_price' => 25,
            ]],
        ];
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

    // ═══════════════════════════════════════════════════════════════════════
    // The bug: creation must use available (on hand − other documents' holds)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_standalone_delivery_order_creation_refuses_to_take_stock_reserved_for_another_order(): void
    {
        // Order B holds 8 of the 10 units. Only 2 are genuinely available.
        $reservingOrderId = $this->confirmedSalesOrder(8);
        $this->assertSame(8.0, $this->reservedOnHand(), 'Fixture: order B reserved 8 units');
        $this->assertSame(
            8.0,
            (float) StockReservation::where('source_document_type', 'SalesOrder')
                ->where('source_document_id', $reservingOrderId)
                ->sum('quantity'),
            'Fixture: the hold is an active StockReservation row'
        );

        // A standalone delivery order for 5 units (on hand 10) must be refused:
        // 3 of those units belong to order B.
        $response = $this->apiPost(
            '/api/v1/sales/delivery-orders',
            $this->standaloneDeliveryOrderPayload(5)
        );

        $this->assertSame(
            422,
            $response->status(),
            'Creating the standalone DO must be refused: 5 units would consume stock reserved for order B. Got: '.$response->getContent()
        );

        $this->assertSame(
            0,
            DeliveryOrder::where('customer_id', $this->customer->id)->count(),
            'No delivery order may be created from the refused request'
        );
        $this->assertSame((float) self::OPENING_STOCK, $this->stockOnHand(), 'On-hand stock is untouched');
        $this->assertSame(8.0, $this->reservedOnHand(), 'Order B keeps its full reservation');
    }

    public function test_standalone_delivery_order_creation_allows_quantity_within_available(): void
    {
        $this->confirmedSalesOrder(8);

        // Exactly the remaining 2 units are available to this document.
        $response = $this->apiPost(
            '/api/v1/sales/delivery-orders',
            $this->standaloneDeliveryOrderPayload(2)
        );

        $response->assertCreated();
        $this->assertSame(
            1,
            DeliveryOrder::where('customer_id', $this->customer->id)->count(),
            'The document may be created from the genuinely available quantity'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The create page's stock check: on hand, reserved, available
    // ═══════════════════════════════════════════════════════════════════════

    public function test_standalone_delivery_order_stock_check_reports_on_hand_reserved_and_available(): void
    {
        $this->confirmedSalesOrder(8);

        $response = $this->actingAs($this->admin)->getJson(
            '/api/v1/sales/delivery-orders/stock-check?'
            .http_build_query([
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
            ])
        );

        $response->assertOk();
        $response->assertJson([
            'on_hand' => 10,
            'reserved' => 8,
        ]);
        $this->assertSame(2.0, (float) $response->json('available'));
    }

    public function test_standalone_delivery_order_stock_check_is_zero_reserved_without_holds(): void
    {
        $response = $this->actingAs($this->admin)->getJson(
            '/api/v1/sales/delivery-orders/stock-check?'
            .http_build_query([
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
            ])
        );

        $response->assertOk();
        $response->assertJson([
            'on_hand' => 10,
            'reserved' => 0,
        ]);
        $this->assertSame(10.0, (float) $response->json('available'));
    }
}

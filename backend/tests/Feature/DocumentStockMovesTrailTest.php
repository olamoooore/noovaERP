<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #690 (SALES-INV / INV-UX4): the "Stock moves" tab on every sales
 * document must expose the real warehouse trail for that document:
 *
 *   - every movement (type, quantity, lot/serial, warehouse, date, source
 *     document) with its reversal link (a `return` movement points at the
 *     `sale` movement it compensates and vice versa);
 *   - the net issued quantity per line (sales minus reversals/returns,
 *     bundle components decomposed — #130);
 *   - a mismatch flag when the net issued quantity differs from the sold
 *     quantity on the document's line.
 *
 * Seeding drives the real routes (router → middleware → controller): the
 * scenario is created with POST /sales/orders, /sales/delivery-orders,
 * /invoices and their lifecycle actions; only the assertion under test is the
 * new stock-moves endpoint, also hit through the router.
 */
class DocumentStockMovesTrailTest extends TenantTestCase
{
    private const OPENING_STOCK = 100;

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
            'name' => 'Stock Moves Trail Tenant',
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

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);

        $this->warehouse = Warehouse::create([
            'name' => 'Trail Warehouse',
            'code' => 'TW-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Trail Widget',
            'sku' => 'TWX-'.uniqid(),
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

    /** Create a confirmed sales order for $quantity units through the real route. */
    private function confirmedSalesOrder(float $quantity): int
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
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

        // Store set-up only (same as HR682): POST /sales/orders does not stamp
        // warehouse_id, but the invoice must resolve an inventory warehouse.
        \Modules\Sales\Models\SalesOrder::whereKey($orderId)
            ->update(['warehouse_id' => $this->warehouse->id]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/orders/{$orderId}/confirm")
            ->assertOk();

        return $orderId;
    }

    private function deliveryOrderFor(int $orderId, float $quantity): int
    {
        $order = \Modules\Sales\Models\SalesOrder::with('lines')->findOrFail($orderId);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/sales/delivery-orders', [
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

    private function getStockMoves(string $uri): array
    {
        $response = $this->actingAs($this->admin)->getJson($uri);
        $response->assertOk();

        return $response->json('data');
    }

    /**
     * A completed delivery issues 5 units; reversing it puts the same 5 back.
     * The tab must show both movements, link them to each other, and report
     * net issued 0 against sold 5 — a mismatch.
     */
    public function test_delivery_order_trail_links_the_reversal_and_flags_the_mismatch(): void
    {
        $orderId = $this->confirmedSalesOrder(5);
        $deliveryOrderId = $this->deliveryOrderFor($orderId, 5);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete")
            ->assertOk();

        $before = $this->getStockMoves("/api/v1/sales/delivery-orders/{$deliveryOrderId}/stock-moves");

        $this->assertCount(1, $before['movements'], 'Completing the delivery records exactly one sale movement.');
        $sale = $before['movements'][0];

        $this->assertSame('sale', $sale['type']);
        $this->assertSame(5.0, (float) $sale['quantity']);
        $this->assertSame('out', $sale['direction']);
        $this->assertSame('DeliveryOrder', $sale['source_document']['type']);
        $this->assertSame($deliveryOrderId, (int) $sale['source_document']['id']);
        $this->assertSame($this->warehouse->id, (int) $sale['warehouse']['id']);
        $this->assertNotNull($sale['date']);
        $this->assertNull($sale['reverses_movement_id']);
        $this->assertNull($sale['reversed_by_movement_id'], 'Nothing has reversed this sale yet.');

        $this->assertCount(1, $before['lines']);
        $this->assertSame(5.0, (float) $before['lines'][0]['sold_quantity']);
        $this->assertSame(5.0, (float) $before['lines'][0]['net_issued_quantity']);
        $this->assertFalse((bool) $before['lines'][0]['mismatch']);
        $this->assertFalse((bool) $before['has_mismatch']);

        // Reverse the completed delivery: the warehouse trail gains the return.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/reverse", [
                'reason' => 'Trail tab test reversal',
            ])
            ->assertOk();

        $after = $this->getStockMoves("/api/v1/sales/delivery-orders/{$deliveryOrderId}/stock-moves");

        $this->assertCount(2, $after['movements'], 'The reversal appends a return movement, it never deletes the sale.');
        $byType = collect($after['movements'])->keyBy('type');
        $sale = $byType['sale'];
        $return = $byType['return'];

        $this->assertSame(5.0, (float) $return['quantity']);
        $this->assertSame('in', $return['direction']);
        $this->assertSame('DeliveryOrder', $return['source_document']['type']);
        $this->assertSame($deliveryOrderId, (int) $return['source_document']['id']);

        $this->assertSame((int) $sale['id'], (int) $return['reverses_movement_id'],
            'The return movement must point at the sale movement it compensates.');
        $this->assertSame((int) $return['id'], (int) $sale['reversed_by_movement_id'],
            'The sale movement must point at the movement that reversed it.');

        $this->assertSame(0.0, (float) $after['lines'][0]['net_issued_quantity'],
            'Net issued = 5 sold - 5 reversed = 0.');
        $this->assertTrue((bool) $after['lines'][0]['mismatch']);
        $this->assertTrue((bool) $after['has_mismatch']);
    }

    /**
     * A partially delivered order: the sales-order tab aggregates every
     * document in the order's goods-issue bucket (the DO, then the invoice for
     * the remainder) and flags the line until issued == sold.
     */
    public function test_sales_order_trail_aggregates_delivery_and_invoice_net_per_line(): void
    {
        $orderId = $this->confirmedSalesOrder(10);
        $deliveryOrderId = $this->deliveryOrderFor($orderId, 4);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete")
            ->assertOk();

        $partial = $this->getStockMoves("/api/v1/sales/orders/{$orderId}/stock-moves");

        $this->assertCount(1, $partial['movements'], 'The order trail shows the delivery that shipped part of it.');
        $this->assertSame('DeliveryOrder', $partial['movements'][0]['source_document']['type']);
        $this->assertSame(4.0, (float) $partial['movements'][0]['quantity']);
        $this->assertSame(10.0, (float) $partial['lines'][0]['sold_quantity']);
        $this->assertSame(4.0, (float) $partial['lines'][0]['net_issued_quantity']);
        $this->assertTrue((bool) $partial['lines'][0]['mismatch'], 'Issued 4 of 10 sold is a mismatch.');
        $this->assertTrue((bool) $partial['has_mismatch']);

        // The delivery's own tab agrees with itself: sold 4, issued 4.
        $delivery = $this->getStockMoves("/api/v1/sales/delivery-orders/{$deliveryOrderId}/stock-moves");
        $this->assertSame(4.0, (float) $delivery['lines'][0]['sold_quantity']);
        $this->assertSame(4.0, (float) $delivery['lines'][0]['net_issued_quantity']);
        $this->assertFalse((bool) $delivery['has_mismatch']);

        // Bill the whole order: the invoice issues only the 6 units the
        // delivery had not shipped (#682), so the order nets to 10/10.
        $invoiceResponse = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/orders/{$orderId}/invoice");
        $invoiceResponse->assertCreated();
        $invoiceId = (int) $invoiceResponse->json('invoice.id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoiceId}/post")
            ->assertOk();

        $complete = $this->getStockMoves("/api/v1/sales/orders/{$orderId}/stock-moves");

        $this->assertCount(2, $complete['movements'], 'The order trail now carries the delivery and the invoice movements.');
        $this->assertSame(
            ['DeliveryOrder', 'Invoice'],
            collect($complete['movements'])->pluck('source_document.type')->unique()->sort()->values()->all()
        );
        $this->assertSame(10.0, (float) $complete['lines'][0]['net_issued_quantity'],
            'Net issued across the bucket = 4 delivered + 6 billed = 10 sold.');
        $this->assertFalse((bool) $complete['lines'][0]['mismatch']);
        $this->assertFalse((bool) $complete['has_mismatch']);
    }

    /**
     * A credit note is money-only (no stock moves of its own), so its tab
     * shows the trail of the invoice being credited.
     */
    public function test_credit_note_tab_shows_the_credited_invoices_movements(): void
    {
        $invoiceResponse = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 4,
                'unit_price' => 25,
                'tax_rate' => 0,
            ]],
        ]);
        $invoiceResponse->assertCreated();
        $invoiceId = (int) $invoiceResponse->json('id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoiceId}/post")
            ->assertOk();

        $creditNoteResponse = $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/customers/credit-notes', [
                'customer_id' => $this->customer->id,
                'invoice_id' => $invoiceId,
                'credit_note_date' => now()->toDateString(),
                'lines' => [[
                    'product_id' => $this->product->id,
                    'description' => $this->product->name,
                    'quantity' => 4,
                    'unit_price' => 25,
                    'tax_rate' => 0,
                ]],
            ]);
        $creditNoteResponse->assertCreated();
        $creditNoteId = (int) $creditNoteResponse->json('id');

        $trail = $this->getStockMoves("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/stock-moves");

        $this->assertCount(1, $trail['movements'], 'The credit note shows the movement that shipped the credited goods.');
        $this->assertSame('Invoice', $trail['movements'][0]['source_document']['type']);
        $this->assertSame($invoiceId, (int) $trail['movements'][0]['source_document']['id']);
        $this->assertSame(4.0, (float) $trail['movements'][0]['quantity']);
        $this->assertSame(4.0, (float) $trail['lines'][0]['net_issued_quantity']);
        $this->assertFalse((bool) $trail['has_mismatch']);
    }

    /**
     * A quotation that has no downstream order yet has no warehouse trail: an
     * explicit empty movement list, not an error.
     */
    public function test_quotation_without_downstream_documents_reports_an_empty_trail(): void
    {
        $quotation = \App\Models\Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_number' => 'Q-'.uniqid(),
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 125,
            'tax_amount' => 0,
            'total' => 125,
            'created_by' => $this->admin->id,
        ]);

        \App\Models\QuotationLine::create([
            'quotation_id' => $quotation->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 5,
            'unit_price' => 25,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => 125,
        ]);

        $trail = $this->getStockMoves("/api/v1/quotations/{$quotation->id}/stock-moves");

        $this->assertSame([], $trail['movements']);
        $this->assertSame(5.0, (float) $trail['lines'][0]['sold_quantity']);
        $this->assertSame(0.0, (float) $trail['lines'][0]['net_issued_quantity']);
        $this->assertTrue((bool) $trail['has_mismatch'], 'Quoted 5, issued 0 — the tab says so.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\StockMovement;
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
 * Issue #682 (P0, SALES-INV IN2) — stock and COGS were deducted twice whenever
 * an invoice and a delivery order were not linked 1:1.
 *
 *   • DeliveryOrderService::completeDeliveryOrder() always issued stock and
 *     posted COGS — it never asked whether an invoice already did.
 *   • InvoiceService::postInvoice() skipped stock only for a DONE DO whose
 *     invoice_id was this invoice, or when the whole SO was fulfilled /
 *     invoiced / a WorkOrder — and skipped COGS on a different, narrower rule.
 *
 * So the two paths could both issue the same goods (stock out = 2x the quantity
 * sold, COGS recognised twice), or stock could be skipped while COGS still
 * posted (SO marked "fulfilled" by Fulfil without any delivery, #373).
 *
 * Every test below drives the REAL routes (router -> middleware -> controller)
 * and asserts the business rule from the issue, not today's behaviour:
 * total stock out == quantity sold, and COGS recognised exactly once.
 */
class HR682GoodsIssueDoubleIssueTest extends TenantTestCase
{
    private const UNIT_COST = 10.00;

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
            'name' => 'Goods Issue Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        // Admin carries sales.write, invoices.write and accounting.post
        // (RolePermissionSeeder::PERMISSION_MATRIX), so one actor can drive the
        // whole quote -> deliver -> bill -> post chain through real middleware.
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

        // Both the invoice and the delivery-order COGS entry need an open period.
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
            'name' => 'Goods Issue Warehouse',
            'code' => 'GIW-'.uniqid(),
            'is_active' => true,
            // A direct (accounting-origin) invoice has no order to resolve a
            // warehouse from, so it falls back to the tenant default.
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Goods Issue Widget',
            'sku' => 'GIW-'.uniqid(),
            'track_inventory' => true,
            'cost' => self::UNIT_COST,
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

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
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

    /** Create a confirmed sales order for $quantity units. */
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

        // Store-set-up only: POST /sales/orders does not expose warehouse_id
        // (sales_orders.warehouse_id is stamped by the quotation/channel
        // paths), but the invoice must resolve a warehouse from its order.
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

    private function completeDeliveryOrder(int $deliveryOrderId): void
    {
        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete")
            ->assertOk();
    }

    private function postInvoice(int $invoiceId): void
    {
        $this->apiPost("/api/v1/invoices/{$invoiceId}/post")->assertOk();
    }

    /** Total quantity of the product that left the warehouse as a sale. */
    private function stockOut(): float
    {
        return (float) StockMovement::where('type', 'sale')
            ->where('product_id', $this->product->id)
            ->sum('quantity');
    }

    private function stockOnHand(): float
    {
        return (float) StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
    }

    /** Net COGS recognised on the GL, across every document. */
    private function cogsRecognised(): float
    {
        $cogsAccountId = app(AccountMappingService::class)->resolveId('cogs');

        return round(
            (float) JournalEntryLine::where('account_id', $cogsAccountId)->sum('debit')
            - (float) JournalEntryLine::where('account_id', $cogsAccountId)->sum('credit'),
            2
        );
    }

    /** COGS recognised on the entry posted by one specific source document. */
    private function cogsForDocument(string $sourceDocumentType, int $sourceDocumentId): float
    {
        $cogsAccountId = app(AccountMappingService::class)->resolveId('cogs');

        $entryIds = \App\Models\JournalEntryLink::where('source_document_type', $sourceDocumentType)
            ->where('source_document_id', $sourceDocumentId)
            ->where('relation_type', 'post')
            ->pluck('journal_entry_id');

        return round((float) JournalEntryLine::whereIn('journal_entry_id', $entryIds)
            ->where('account_id', $cogsAccountId)
            ->sum('debit'), 2);
    }

    /**
     * The data check from the issue: no sales-order line may be issued by BOTH
     * an invoice and a delivery order for the same goods.
     *
     * Returns the offending sales-order line ids (empty when the ledger is clean).
     */
    private function salesOrderLinesIssuedTwice(): array
    {
        $rows = DB::table('stock_movements as sm')
            ->where('sm.type', 'sale')
            ->whereIn('sm.reference_type', ['Invoice', 'DeliveryOrder'])
            ->where('sm.product_id', $this->product->id)
            ->join('delivery_orders as d', function ($join) {
                $join->on('d.id', '=', 'sm.reference_id')
                    ->where('sm.reference_type', '=', 'DeliveryOrder');
            })
            ->join('sales_orders as so', 'so.id', '=', 'd.sales_order_id')
            ->join('sales_order_lines as sol', 'sol.sales_order_id', '=', 'so.id')
            ->join('invoices as i', 'i.id', '=', 'sm.reference_id')
            ->whereRaw("sm.reference_type = 'Invoice' AND i.sales_order_id = so.id")
            ->groupBy('sol.id')
            ->selectRaw('sol.id as sales_order_line_id, count(*) as issues')
            ->get();

        return $rows->pluck('sales_order_line_id')->map(fn ($id) => (int) $id)->all();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario 1 — invoice posted first, delivery completed afterwards
    // ═══════════════════════════════════════════════════════════════════════

    public function test_invoice_first_then_delivery_issues_stock_and_cogs_once(): void
    {
        $orderId = $this->confirmedSalesOrder(10);
        $invoiceId = $this->invoiceFromSalesOrder($orderId);

        $this->postInvoice($invoiceId);

        $this->assertSame(10.0, $this->stockOut(), 'The invoice must issue the 10 units it billed');
        $this->assertSame(90.0, $this->stockOnHand());
        $this->assertSame(100.0, $this->cogsRecognised(), 'COGS for 10 units @ 10.00');

        // Now the same goods are delivered — the DO must NOT issue them again.
        $deliveryOrderId = $this->deliveryOrderFor($orderId, 10);
        $this->completeDeliveryOrder($deliveryOrderId);

        $this->assertSame(10.0, $this->stockOut(),
            'Stock must leave the warehouse once: the invoice already issued these 10 units');
        $this->assertSame(90.0, $this->stockOnHand(),
            'Stock on hand must reflect the 10 units sold — not 20');
        $this->assertSame(100.0, $this->cogsRecognised(),
            'COGS must be recognised once for the 10 units sold, not twice');
        $this->assertSame(0.0, $this->cogsForDocument('delivery_order', $deliveryOrderId),
            'The delivery order posted no goods, so it may recognise no COGS');
        $this->assertSame([], $this->salesOrderLinesIssuedTwice(),
            'Data check: no sales-order line may carry an Invoice-type and a DeliveryOrder-type issue');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario 2 — SO -> partial DO -> "Create invoice" from the SO
    // ═══════════════════════════════════════════════════════════════════════

    public function test_partial_delivery_then_so_invoice_issues_the_remainder_once(): void
    {
        $orderId = $this->confirmedSalesOrder(100);

        $deliveryOrderId = $this->deliveryOrderFor($orderId, 40);
        $this->completeDeliveryOrder($deliveryOrderId);

        $this->assertSame(40.0, $this->stockOut(), 'The delivery issued the 40 units it shipped');
        $this->assertSame(400.0, $this->cogsRecognised());

        // "Create invoice" from the sales order bills the ordered 100 (the
        // default ordered_qty policy); only the 60 undelivered units are new.
        $invoiceId = $this->invoiceFromSalesOrder($orderId);
        $this->postInvoice($invoiceId);

        $this->assertSame(100.0, $this->stockOut(),
            'Total stock out must equal the 100 units sold');
        $this->assertSame(0.0, $this->stockOnHand());
        $this->assertSame(1000.0, $this->cogsRecognised(),
            'COGS for the 100 units sold must be recognised exactly once across both documents');
        $this->assertSame(400.0, $this->cogsForDocument('delivery_order', $deliveryOrderId));
        $this->assertSame(600.0, $this->cogsForDocument('invoice', $invoiceId),
            'The invoice recognises only the 60 units the delivery had not issued');
        $this->assertSame([], $this->salesOrderLinesIssuedTwice(),
            'Data check: no sales-order line may carry an Invoice-type and a DeliveryOrder-type issue');
    }

    public function test_full_delivery_then_so_invoice_recognises_no_further_goods(): void
    {
        $orderId = $this->confirmedSalesOrder(25);

        $deliveryOrderId = $this->deliveryOrderFor($orderId, 25);
        $this->completeDeliveryOrder($deliveryOrderId);

        $invoiceId = $this->invoiceFromSalesOrder($orderId);
        $this->postInvoice($invoiceId);

        $this->assertSame(25.0, $this->stockOut(), 'Total stock out must equal the 25 units sold');
        $this->assertSame(75.0, $this->stockOnHand());
        $this->assertSame(250.0, $this->cogsRecognised(), 'COGS recognised once for the 25 units sold');
        $this->assertSame(0.0, $this->cogsForDocument('invoice', $invoiceId),
            'The invoice recognised no goods because the delivery already did');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario 3 — invoice -> delivery order (#293 standalone-delivery flow)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_delivery_order_from_posted_invoice_does_not_reissue_goods(): void
    {
        $response = $this->apiPost('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 8,
                'unit_price' => 25,
                'tax_rate' => 0,
            ]],
        ]);
        $response->assertCreated();
        $invoiceId = (int) $response->json('id');

        $this->postInvoice($invoiceId);

        $this->assertSame(8.0, $this->stockOut(), 'The invoice issued the 8 units it billed');
        $this->assertSame(80.0, $this->cogsRecognised());

        // Turn the invoice into a delivery order and ship it: the DO must not
        // issue the same goods a second time.
        $create = $this->apiPost("/api/v1/invoices/{$invoiceId}/create-delivery-order");
        $create->assertCreated();
        $deliveryOrderId = (int) $create->json('data.id');

        $this->completeDeliveryOrder($deliveryOrderId);

        $this->assertSame(8.0, $this->stockOut(),
            'Stock must leave the warehouse once: the posted invoice already issued these 8 units');
        $this->assertSame(92.0, $this->stockOnHand());
        $this->assertSame(80.0, $this->cogsRecognised(),
            'COGS must be recognised once for the 8 units sold, not twice');
        $this->assertSame(0.0, $this->cogsForDocument('delivery_order', $deliveryOrderId));
    }

    public function test_invoice_raised_from_completed_delivery_order_does_not_reissue_goods(): void
    {
        // Standalone delivery order, shipped first.
        $created = $this->apiPost('/api/v1/sales/delivery-orders', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity_delivered' => 6,
                'unit_price' => 25,
            ]],
        ]);
        $created->assertCreated();
        $deliveryOrderId = (int) $created->json('data.id');
        $this->completeDeliveryOrder($deliveryOrderId);

        $this->assertSame(6.0, $this->stockOut());
        $this->assertSame(60.0, $this->cogsRecognised());

        // Then the delivery is billed: the invoice recognises revenue only.
        $invoice = $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/create-invoice");
        $invoice->assertCreated();
        $this->postInvoice((int) $invoice->json('data.id'));

        $this->assertSame(6.0, $this->stockOut(), 'Total stock out must equal the 6 units sold');
        $this->assertSame(94.0, $this->stockOnHand());
        $this->assertSame(60.0, $this->cogsRecognised(), 'COGS recognised once for the 6 units sold');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // One rule for stock AND COGS (#373: fulfilled without delivery)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_invoice_form_order_fulfilled_without_delivery_issues_stock_and_cogs(): void
    {
        // POST /invoices fast-tracks the backing sales order through
        // confirm -> allocate -> fulfil WITHOUT any delivery order, so nothing
        // has left the warehouse by the time the invoice is posted. The "SO is
        // fulfilled/invoiced" skip rule then withheld the stock movement while
        // COGS was still recognised (#373).
        $response = $this->apiPost('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 12,
                'unit_price' => 25,
                'tax_rate' => 0,
            ]],
        ]);
        $response->assertCreated();

        $this->assertSame(0.0, $this->stockOut(), 'Nothing was delivered, so nothing has been issued yet');

        $this->postInvoice((int) $response->json('id'));

        $this->assertSame(12.0, $this->stockOut(),
            'Nothing was delivered, so the invoice must issue the 12 units it billed');
        $this->assertSame(88.0, $this->stockOnHand());
        $this->assertSame(120.0, $this->cogsRecognised(),
            'Stock and COGS must follow the same rule: 12 units sold, COGS 120.00 once');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Regression guards — a single delivery still issues its own goods
    // ═══════════════════════════════════════════════════════════════════════

    public function test_a_single_delivery_still_issues_stock_and_cogs(): void
    {
        $orderId = $this->confirmedSalesOrder(15);
        $deliveryOrderId = $this->deliveryOrderFor($orderId, 15);
        $this->completeDeliveryOrder($deliveryOrderId);

        $this->assertSame(15.0, $this->stockOut());
        $this->assertSame(85.0, $this->stockOnHand());
        $this->assertSame(150.0, $this->cogsRecognised());
        $this->assertSame(150.0, $this->cogsForDocument('delivery_order', $deliveryOrderId));
    }

    public function test_a_direct_invoice_still_issues_stock_and_cogs(): void
    {
        $response = $this->apiPost('/api/v1/invoices', [
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
        $response->assertCreated();
        $this->postInvoice((int) $response->json('id'));

        $this->assertSame(4.0, $this->stockOut());
        $this->assertSame(96.0, $this->stockOnHand());
        $this->assertSame(40.0, $this->cogsRecognised());
    }
}
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BundleDecompositionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Ecommerce\Models\WebOrderItem;
use Modules\Ecommerce\Services\WebOrderPostingService;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Services\DeliveryOrderService;
use Tests\TenantTestCase;

/**
 * Issue #130 (HIGH) — selling a bundle/kit never decomposed into its component
 * products: component stock never moved and bundle COGS was the bundle
 * product's own (usually unset) cost.
 *
 * Verification from the issue: sell a bundle with 2 known components, assert
 * both components' stock levels decrease by the correct quantity, and the
 * resulting COGS equals the sum of the components' costs.
 */
class HR130BundleDecompositionTest extends TenantTestCase
{
    private Product $bundle;

    private Product $componentA;

    private Product $componentB;

    private Warehouse $warehouse;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) Str::uuid();
        $this->bindTenantContextWithPermissions($tenantId);

        $this->assertTenantSchemaReady(['products', 'product_bundles', 'warehouses', 'stock_levels', 'delivery_orders']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'name' => 'Bundle Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::create([
            'name' => 'Bundle Warehouse',
            'code' => 'BND-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        // Bundle is virtual (assembled on sale) — not stock-tracked itself.
        $this->bundle = Product::factory()->create([
            'name' => 'Gift Basket',
            'sku' => 'BND-'.uniqid(),
            'is_bundle' => true,
            'track_inventory' => false,
            'price' => 50,
            'cost' => 0,
        ]);

        $this->componentA = Product::factory()->create([
            'name' => 'Red Wine',
            'sku' => 'CMP-A-'.uniqid(),
            'track_inventory' => true,
            'price' => 20,
            'cost' => 5.00,
        ]);

        $this->componentB = Product::factory()->create([
            'name' => 'Chocolate',
            'sku' => 'CMP-B-'.uniqid(),
            'track_inventory' => true,
            'price' => 10,
            'cost' => 3.00,
        ]);

        // 1 Gift Basket = 2 Red Wine + 1 Chocolate.
        ProductBundle::create([
            'bundle_product_id' => $this->bundle->id,
            'component_product_id' => $this->componentA->id,
            'quantity' => 2,
        ]);
        ProductBundle::create([
            'bundle_product_id' => $this->bundle->id,
            'component_product_id' => $this->componentB->id,
            'quantity' => 1,
        ]);

        foreach ([$this->componentA, $this->componentB] as $component) {
            StockLevel::create([
                'product_id' => $component->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 10,
                'reserved_quantity' => 0,
                'min_quantity' => 0,
            ]);
        }
    }

    private function stockOf(Product $product): int
    {
        return (int) StockLevel::where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
    }

    private function deliver(int $quantity): void
    {
        $service = app(DeliveryOrderService::class);

        $do = $service->createStandaloneDeliveryOrder([
            'customer_id' => Customer::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->bundle->id,
                'description' => 'Gift Basket',
                'quantity_delivered' => $quantity,
                'unit' => 'pcs',
            ]],
        ]);

        $service->completeDeliveryOrder($do);
    }

    // ─────────────────────────────────────────────────────────────────────
    // The issue's verification
    // ─────────────────────────────────────────────────────────────────────

    public function test_selling_a_bundle_decomposes_into_component_stock_movements(): void
    {
        $this->deliver(3); // 3 Gift Baskets = 6 Red Wine + 3 Chocolate

        $this->assertSame(4, $this->stockOf($this->componentA), 'Red Wine must drop by 2 x 3 = 6');
        $this->assertSame(7, $this->stockOf($this->componentB), 'Chocolate must drop by 1 x 3 = 3');

        // Stock movements were recorded against the components, not the bundle.
        $movementA = DB::table('stock_movements')->where('product_id', $this->componentA->id)->where('type', 'sale')->first();
        $movementB = DB::table('stock_movements')->where('product_id', $this->componentB->id)->where('type', 'sale')->first();

        $this->assertNotNull($movementA);
        $this->assertNotNull($movementB);
        $this->assertSame(6, (int) $movementA->quantity, 'Component A movement carries the decomposed 2 x 3 quantity');
        $this->assertSame(3, (int) $movementB->quantity, 'Component B movement carries the decomposed 1 x 3 quantity');
        $this->assertSame(0, DB::table('stock_movements')->where('product_id', $this->bundle->id)->count(), 'The virtual bundle itself must not move stock');
    }

    public function test_bundle_cogs_is_the_sum_of_component_costs(): void
    {
        $cost = app(BundleDecompositionService::class)->cost($this->bundle, 3);

        // (5.00 x 2 + 3.00 x 1) x 3 = 39.00 — not the bundle product's cost (0).
        $this->assertSame(39.0, $cost);
    }

    public function test_non_bundle_lines_are_unchanged(): void
    {
        $service = app(DeliveryOrderService::class);

        $do = $service->createStandaloneDeliveryOrder([
            'customer_id' => Customer::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->componentA->id,
                'description' => 'Red Wine',
                'quantity_delivered' => 2,
                'unit' => 'pcs',
            ]],
        ]);

        $service->completeDeliveryOrder($do);

        $this->assertSame(8, $this->stockOf($this->componentA));
        $this->assertSame(10, $this->stockOf($this->componentB));
        $this->assertSame(10.0, app(BundleDecompositionService::class)->cost($this->componentA, 2));
    }

    public function test_decomposition_expands_scaled_component_quantities(): void
    {
        $components = app(BundleDecompositionService::class)->stockConsumingComponents($this->bundle, 2.5);

        $byId = collect($components)->keyBy(fn ($entry) => $entry['product']->id);

        $this->assertSame(5.0, (float) $byId[$this->componentA->id]['quantity']);
        $this->assertSame(2.5, (float) $byId[$this->componentB->id]['quantity']);
    }

    public function test_web_order_channel_decomposes_bundle_stock(): void
    {
        $order = WebOrder::create([
            'order_number' => 'WEB-'.Str::upper(Str::random(8)),
            'customer_id' => Customer::factory()->create()->id,
            'email' => 'bundle-'.uniqid().'@test.local',
            'first_name' => 'Bundle',
            'last_name' => 'Buyer',
            'status' => 'pending',
            'payment_status' => 'pending',
            'subtotal' => 100,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100,
            'currency' => 'USD',
            'fulfillment_warehouse_id' => $this->warehouse->id,
        ]);

        WebOrderItem::create([
            'web_order_id' => $order->id,
            'product_id' => $this->bundle->id,
            'product_name' => 'Gift Basket',
            'product_sku' => $this->bundle->sku,
            'unit_price' => 50,
            'quantity' => 2,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 100,
        ]);

        app(WebOrderPostingService::class)->deductStock($order->fresh('items.product'));

        // 2 Gift Baskets = 4 Red Wine + 2 Chocolate.
        $this->assertSame(6, $this->stockOf($this->componentA), 'Web order must deduct 2 x 2 components');
        $this->assertSame(8, $this->stockOf($this->componentB), 'Web order must deduct 2 x 1 components');
    }

    /**
     * Issue #241: direct invoice-only bundle sale — the #130/#131 fix wired
     * bundle COGS into InvoiceService but never decomposed component stock.
     */
    public function test_direct_invoice_only_bundle_sale_decomposes_component_stock(): void
    {
        // Direct invoices have no linked order for warehouse resolution — the
        // service falls back to the tenant default warehouse (set in setUp).

        $invoice = \App\Models\Invoice::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'created_by' => $this->user->id,
            // Totals must match the single line below or the posting engine
            // (correctly) rejects the journal as unbalanced.
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100,
            'paid_amount' => 0,
            'balance' => 100,
        ]);

        \App\Models\InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->bundle->id,
            'description' => 'Gift Basket',
            'quantity' => 2,
            'unit_price' => 50,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 100,
        ]);

        app(\App\Services\InvoiceService::class)
            ->postInvoice($invoice->fresh(['lines.product', 'customer']));

        // 2 Gift Baskets = 4 Red Wine + 2 Chocolate.
        $this->assertSame(6, $this->stockOf($this->componentA),
            'Invoice-only bundle sale must decompose component stock (GL already did)');
        $this->assertSame(8, $this->stockOf($this->componentB));
    }

    /**
     * Issue #243: a web order with a standalone component line AND a bundle
     * line sharing that component previously skipped the bundle's share — the
     * guard summed "already deducted" by product across the whole order.
     */
    public function test_shared_component_across_web_order_lines_is_fully_deducted(): void
    {
        $order = WebOrder::create([
            'order_number' => 'WEB-'.Str::upper(Str::random(8)),
            'customer_id' => Customer::factory()->create()->id,
            'email' => 'shared-'.uniqid().'@test.local',
            'first_name' => 'Shared',
            'last_name' => 'Buyer',
            'status' => 'pending',
            'payment_status' => 'pending',
            'subtotal' => 70,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 70,
            'currency' => 'USD',
            'fulfillment_warehouse_id' => $this->warehouse->id,
        ]);

        // Standalone: 2 Chocolate.
        WebOrderItem::create([
            'web_order_id' => $order->id,
            'product_id' => $this->componentB->id,
            'product_name' => 'Chocolate',
            'product_sku' => $this->componentB->sku,
            'unit_price' => 10,
            'quantity' => 2,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 20,
        ]);
        // Bundle: 1 Gift Basket = 2 Red Wine + 1 Chocolate.
        WebOrderItem::create([
            'web_order_id' => $order->id,
            'product_id' => $this->bundle->id,
            'product_name' => 'Gift Basket',
            'product_sku' => $this->bundle->sku,
            'unit_price' => 50,
            'quantity' => 1,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 50,
        ]);

        app(WebOrderPostingService::class)->deductStock($order->fresh('items.product'));

        // Chocolate demand = 2 standalone + 1 bundle = 3 (was 2: bundle share skipped).
        $this->assertSame(7, $this->stockOf($this->componentB),
            'Shared component must be deducted for BOTH lines');
        $this->assertSame(8, $this->stockOf($this->componentA), 'Bundle wine deduction');

        // Restore mirrors the deduction.
        app(WebOrderPostingService::class)->restoreStock($order->fresh('items.product'));
        $this->assertSame(10, $this->stockOf($this->componentB));
        $this->assertSame(10, $this->stockOf($this->componentA));
    }

    /**
     * Issue #242: same cross-line under-deduction on the POS backfill path.
     */
    public function test_shared_component_across_two_pos_lines_is_fully_deducted(): void
    {
        $terminalId = \DB::table('pos_terminals')->insertGetId([
            'name' => 'Bundle Terminal', 'code' => 'BND-T-'.Str::upper(Str::random(4)),
            'warehouse_id' => $this->warehouse->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sessionId = \DB::table('pos_sessions')->insertGetId([
            'terminal_id' => $terminalId, 'user_id' => $this->user->id,
            'session_number' => 'BND-S-'.Str::upper(Str::random(4)), 'status' => 'open',
            'opening_balance' => 0, 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $journalEntryId = \DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-BND-'.Str::upper(Str::random(6)),
            'entry_date' => now()->toDateString(),
            'type' => 'general',
            'status' => 'posted',
            'created_by' => $this->user->id,
            'is_reversal' => false,
            'lifecycle_status' => 'posted',
            'version_number' => 1,
            'is_active_version' => true,
            'is_period_close_entry' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = \Modules\POS\Models\PosOrder::create([
            'external_id' => (string) Str::uuid(),
            'order_number' => 'POS-'.Str::upper(Str::random(8)),
            'status' => 'paid',
            'session_id' => $sessionId,
            'terminal_id' => $terminalId,
            'created_by' => $this->user->id,
            'subtotal' => 70,
            'tax_amount' => 0,
            'total' => 70,
            'discount_value' => 0,
            'discount_amount' => 0,
            'is_return' => false,
            'loyalty_points_earned' => 0,
            'loyalty_points_redeemed' => 0,
            'journal_entry_id' => $journalEntryId,
            'currency' => 'USD',
        ]);

        \Modules\POS\Models\PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => $this->componentB->id,
            'name' => 'Chocolate',
            'quantity' => 2,
            'unit_price' => 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 20,
        ]);
        \Modules\POS\Models\PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => $this->bundle->id,
            'name' => 'Gift Basket',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 50,
        ]);

        app(\Modules\POS\Services\PosPaymentCompletionService::class)
            ->backfill($order->fresh(), 'cash', 'BND-REF-'.Str::upper(Str::random(6)));

        $this->assertSame(7, $this->stockOf($this->componentB),
            'POS shared component must be deducted for both lines');
        $this->assertSame(8, $this->stockOf($this->componentA));
    }

    /**
     * Issue #252: serials were only marked sold via the Sales-Order→Delivery-
     * Order channel; POS and WebOrder sales left them 'available'.
     */
    public function test_serialized_products_are_marked_sold_on_pos_and_web_sales(): void
    {
        $serialProduct = Product::factory()->create([
            'name' => 'Serial Widget',
            'sku' => 'SN-'.Str::upper(Str::random(6)),
            'track_inventory' => true,
            'tracking_method' => Product::TRACKING_SERIAL,
            'price' => 100,
            'cost' => 50,
        ]);
        StockLevel::create([
            'product_id' => $serialProduct->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);
        \App\Models\ProductSerial::create([
            'product_id' => $serialProduct->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_number' => 'SN-POS-1',
            'status' => \App\Models\ProductSerial::STATUS_AVAILABLE,
        ]);
        \App\Models\ProductSerial::create([
            'product_id' => $serialProduct->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_number' => 'SN-WEB-1',
            'status' => \App\Models\ProductSerial::STATUS_AVAILABLE,
        ]);

        // ---- POS sale ----
        $customer = Customer::factory()->create();
        $terminalId = \DB::table('pos_terminals')->insertGetId([
            'name' => 'Serial Terminal', 'code' => 'SN-T-'.Str::upper(Str::random(4)),
            'warehouse_id' => $this->warehouse->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sessionId = \DB::table('pos_sessions')->insertGetId([
            'terminal_id' => $terminalId, 'user_id' => $this->user->id,
            'session_number' => 'SN-S-'.Str::upper(Str::random(4)), 'status' => 'open',
            'opening_balance' => 0, 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $journalEntryId = \DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-SN-'.Str::upper(Str::random(6)),
            'entry_date' => now()->toDateString(), 'type' => 'general', 'status' => 'posted',
            'created_by' => $this->user->id, 'is_reversal' => false,
            'lifecycle_status' => 'posted', 'version_number' => 1,
            'is_active_version' => true, 'is_period_close_entry' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $posOrder = \Modules\POS\Models\PosOrder::create([
            'external_id' => (string) Str::uuid(),
            'order_number' => 'POS-SN-'.Str::upper(Str::random(6)),
            'status' => 'paid', 'session_id' => $sessionId, 'terminal_id' => $terminalId,
            'customer_id' => $customer->id, 'created_by' => $this->user->id,
            'subtotal' => 100, 'tax_amount' => 0, 'total' => 100,
            'discount_value' => 0, 'discount_amount' => 0, 'is_return' => false,
            'loyalty_points_earned' => 0, 'loyalty_points_redeemed' => 0,
            'journal_entry_id' => $journalEntryId, 'currency' => 'USD',
        ]);
        \Modules\POS\Models\PosOrderItem::create([
            'pos_order_id' => $posOrder->id, 'product_id' => $serialProduct->id,
            'name' => 'Serial Widget', 'quantity' => 1, 'unit_price' => 100,
            'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 100,
            'serial_numbers' => ['SN-POS-1'],
        ]);

        app(\Modules\POS\Services\PosPaymentCompletionService::class)
            ->backfill($posOrder->fresh(), 'cash', 'SN-REF-'.Str::upper(Str::random(6)));

        $posSerial = \App\Models\ProductSerial::where('serial_number', 'SN-POS-1')->first();
        $this->assertSame('sold', $posSerial->status, 'POS sale must mark the serial sold');
        $this->assertSame($posOrder->order_number, $posSerial->sale_reference);
        $this->assertSame($customer->id, (int) $posSerial->customer_id);

        // ---- Web sale ----
        $webOrder = WebOrder::create([
            'order_number' => 'WEB-SN-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'email' => 'serial-'.uniqid().'@test.local',
            'first_name' => 'Serial', 'last_name' => 'Buyer',
            'status' => 'pending', 'payment_status' => 'pending',
            'subtotal' => 100, 'shipping_cost' => 0, 'tax_amount' => 0,
            'discount_amount' => 0, 'total' => 100, 'currency' => 'USD',
            'fulfillment_warehouse_id' => $this->warehouse->id,
        ]);
        WebOrderItem::create([
            'web_order_id' => $webOrder->id, 'product_id' => $serialProduct->id,
            'product_name' => 'Serial Widget', 'product_sku' => $serialProduct->sku,
            'unit_price' => 100, 'quantity' => 1, 'tax_rate' => 0,
            'tax_amount' => 0, 'line_total' => 100,
            'serial_numbers' => ['SN-WEB-1'],
        ]);

        app(WebOrderPostingService::class)->deductStock($webOrder->fresh('items.product'));

        $webSerial = \App\Models\ProductSerial::where('serial_number', 'SN-WEB-1')->first();
        $this->assertSame('sold', $webSerial->status, 'Web sale must mark the serial sold');
        $this->assertSame($webOrder->order_number, $webSerial->sale_reference);
    }

    public function test_bundle_discount_columns_match_the_real_schema(): void
    {
        foreach (['component_variant_id', 'discount_type', 'discount_value', 'quantity'] as $column) {
            $this->assertTrue(
                DB::getSchemaBuilder()->hasColumn('product_bundles', $column),
                "product_bundles.{$column} is required for bundle decomposition/pricing"
            );
        }
    }
}

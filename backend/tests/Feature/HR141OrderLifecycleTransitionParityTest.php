<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\OrderLifecycleService;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Issue #141 (BLOCKER) — OrderLifecycleService::TRANSITIONS vs the business
 * contracts enforced by SalesOrderService's own method guards.
 *
 * Every assertion below is a REAL service call against a real DB, not a
 * source-string check:
 *
 *  1. createInvoice() from a confirmed order (invoice before fulfilment /
 *     deposit invoicing) must succeed.
 *  2. createInvoice() from an allocated order must succeed.
 *  3. Repeated invoicing (fulfil -> invoice -> invoice again) for partial
 *     quantities must succeed.
 *  4. cancelOrder() of a fulfilled-but-uninvoiced order must succeed.
 *  5. reset-to-draft goes through the state machine and audits the transition.
 *  6. Illegal transitions are still rejected (the table was widened only to
 *     match real guards, not made permissive).
 *  7. The invoiced-order cancellation guard still holds.
 */
class HR141OrderLifecycleTransitionParityTest extends TenantTestCase
{
    protected User $user;

    protected Customer $customer;

    protected Product $productA;

    protected Product $productB;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext('test-tenant');

        $this->assertTenantSchemaReady([
            'customers', 'products', 'sales_orders', 'sales_order_lines',
        ]);

        $this->user = User::create([
            'name' => 'HR141 User',
            'email' => 'hr141-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'HR141 Customer',
            'payment_terms' => 30,
        ]);

        $this->productA = Product::factory()->create([
            'name' => 'HR141 Widget A',
            'sku' => 'HR141-A-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->productB = Product::factory()->create([
            'name' => 'HR141 Widget B',
            'sku' => 'HR141-B-'.uniqid(),
            'price' => 200,
            'cost' => 50,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'HR141 Warehouse',
            'code' => 'HR141-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function createConfirmedOrder(int $linesCount = 1): SalesOrder
    {
        $service = app(SalesOrderService::class);

        $lines = [];
        for ($i = 0; $i < $linesCount; $i++) {
            $product = $i === 0 ? $this->productA : $this->productB;
            $lines[] = [
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 100,
                'unit_price' => $product->price,
                'tax_rate' => 10,
                'discount_amount' => 0,
            ];
        }

        $order = $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => $lines,
        ]);

        return $service->confirmOrder($order);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Verification #1 — invoice from confirmed, no allocation/fulfilment
    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_from_confirmed_order_succeeds(): void
    {
        $order = $this->createConfirmedOrder(1);
        $this->assertSame('confirmed', $order->status);

        $invoice = app(SalesOrderService::class)->createInvoice($order);

        $this->assertNotNull($invoice->id, 'Invoice must actually be created');
        $this->assertSame('invoiced', $order->fresh()->status, 'Order must reach invoiced');
        $this->assertSame(1, $invoice->lines()->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Verification #2 — invoice from allocated (invoice before fulfilment)
    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_from_allocated_order_succeeds(): void
    {
        $svc = app(SalesOrderService::class);
        $order = $this->createConfirmedOrder(1);
        $order = $svc->allocateOrder($order);
        $this->assertSame('allocated', $order->status);

        $invoice = $svc->createInvoice($order);

        $this->assertNotNull($invoice->id);
        $this->assertSame('invoiced', $order->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Verification #3 — repeated invoicing (multi/partial-invoice)
    // ─────────────────────────────────────────────────────────────────────

    public function test_repeated_invoicing_after_fulfilment_succeeds(): void
    {
        $svc = app(SalesOrderService::class);

        // Two lines: A billed on ordered qty, B billed on delivered qty
        // (nothing delivered yet, so only A is invoiceable on the first pass).
        $order = $this->createConfirmedOrder(2);
        $lineBId = $order->lines()->orderBy('id')->skip(1)->value('id');
        SalesOrderLine::whereKey($lineBId)->update([
            'invoicing_policy' => 'delivered_qty',
            'quantity_delivered' => 0,
        ]);

        $order = $svc->allocateOrder($order);
        $order = $svc->fulfilOrder($order);
        $this->assertSame('fulfilled', $order->status);

        // First invoice: only line A.
        $first = $svc->createInvoice($order->fresh());
        $this->assertSame('invoiced', $order->fresh()->status);
        $this->assertSame(1, $first->lines()->count(), 'First invoice should only contain line A');

        // Remaining quantity on line B becomes invoiceable later.
        SalesOrderLine::whereKey($lineBId)->update(['quantity_delivered' => 50]);

        // Second invoice: from an already 'invoiced' order (invoiced -> invoiced).
        $second = $svc->createInvoice($order->fresh());

        $this->assertNotNull($second->id, 'Second (partial) invoice must succeed');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('invoiced', $order->fresh()->status);
        $this->assertSame(1, $second->lines()->count(), 'Second invoice should contain the remaining line B qty');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Verification #4 — cancel a fulfilled-but-unbilled order
    // ─────────────────────────────────────────────────────────────────────

    public function test_cancel_fulfilled_uninvoiced_order_succeeds(): void
    {
        $svc = app(SalesOrderService::class);
        $order = $this->createConfirmedOrder(1);
        $order = $svc->allocateOrder($order);
        $order = $svc->fulfilOrder($order);
        $this->assertSame('fulfilled', $order->status);

        $cancelled = $svc->cancelOrder($order, 'Customer cancelled before billing');

        $this->assertSame('cancelled', $cancelled->status, 'Fulfilled-but-uninvoiced order must be cancellable');
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('Customer cancelled before billing', $cancelled->cancellation_reason);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Verification #5 — reset-to-draft uses the state machine
    // ─────────────────────────────────────────────────────────────────────

    public function test_reset_to_draft_transitions_cancelled_to_draft(): void
    {
        $svc = app(SalesOrderService::class);
        $lifecycle = app(OrderLifecycleService::class);

        $order = $this->createConfirmedOrder(1);
        $order = $svc->cancelOrder($order, 'no longer needed');
        $this->assertSame('cancelled', $order->status);

        $lineId = $order->lines()->orderBy('id')->value('id');
        $this->assertGreaterThan(0, (float) SalesOrderLine::whereKey($lineId)->value('quantity_cancelled'));

        $draft = $lifecycle->resetToDraft($order, 'reopen');

        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->cancelled_at);
        $this->assertNull($draft->cancellation_reason);
        $this->assertSame(0.0, (float) SalesOrderLine::whereKey($lineId)->value('quantity_cancelled'), 'Line cancelled qty must reset');

        // The transition must be audited like every other lifecycle move.
        $this->assertDatabaseHas('sales_order_status_history', [
            'sales_order_id' => $order->id,
            'from_status' => 'cancelled',
            'to_status' => 'draft',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Guard integrity — widening for real contracts must not allow nonsense
    // ─────────────────────────────────────────────────────────────────────

    public function test_illegal_transition_is_still_rejected(): void
    {
        $order = $this->createConfirmedOrder(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot transition sales order #{$order->id} from 'confirmed' to 'fulfilled'");

        app(OrderLifecycleService::class)->transition($order, 'fulfilled');
    }

    public function test_cancelled_order_cannot_be_invoiced(): void
    {
        $svc = app(SalesOrderService::class);
        $order = $this->createConfirmedOrder(1);
        $order = $svc->cancelOrder($order, 'cancelled early');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Only confirmed, allocated, fulfilled, or partially invoiced orders can be invoiced.');

        $svc->createInvoice($order);
    }

    public function test_invoiced_order_cannot_be_cancelled(): void
    {
        $svc = app(SalesOrderService::class);
        $order = $this->createConfirmedOrder(1);
        $order = $svc->allocateOrder($order);
        $order = $svc->fulfilOrder($order);
        $svc->createInvoice($order);
        $this->assertSame('invoiced', $order->fresh()->status);

        $this->expectException(\Exception::class);
        // Issue #154: the message now points at the reachable credit-note action.
        $this->expectExceptionMessage('Invoiced orders cannot be cancelled. Issue a credit note from the order instead.');

        $svc->cancelOrder($order->fresh(), 'too late');
    }
}

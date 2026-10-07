<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #411 (SALES-DIFF DX1): per-line fulfilment chain
 * ordered → reserved → delivered → invoiced → paid, with the documents behind
 * each stage, and a "lines stuck at stage X" filter on the order list.
 *
 * Driven through the real HTTP routes (router → middleware → controller).
 * Fixtures write the same bookkeeping the real allocation / delivery /
 * invoicing / payment paths write: SO-line quantity_allocated (#380),
 * quantity_delivered / quantity_invoiced mirrors (#461 / #455), invoice lines
 * linked by sales_order_line_id, and payment_applications (#155) with
 * reversed_at on reversal (C-3).
 *
 * Paid rule (documented on SalesDocumentProgress): a linked invoice line's
 * paid share = (live payment applications on its invoice ÷ invoice total,
 * capped at 1) × the line's quantity / amount.
 */
class Issue411LineFulfilmentProgressTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private int $entityA;

    private int $entityB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => $this->tenantId]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        CompanySettings::query()->create(['base_currency' => 'USD']);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 411 Group',
            'code' => 'G411-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'E411A-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity B', 'code' => 'E411B-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->admin = $this->makeUser('admin');
        $this->actingAs($this->admin);

        $this->customer = Customer::factory()->create(['name' => '411 Customer', 'legal_entity_id' => $this->entityA]);
        $this->product = Product::factory()->create([
            'name' => '411 Widget',
            'sku' => '411-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @param  int[]  $entityIds */
    private function makeUser(string $role, array $entityIds = []): User
    {
        $user = User::create([
            'name' => "Issue411 {$role}",
            'email' => "issue411-{$role}-".uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole(\App\Enums\UserRole::from($role)->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($entityIds as $index => $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => 'editor',
                'is_default' => $index === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function salesOrder(string $status = 'confirmed', ?int $entityId = null): SalesOrder
    {
        return SalesOrder::create([
            'sales_order_number' => 'SO-411-'.uniqid(),
            'customer_id' => $this->customer->id,
            'legal_entity_id' => $entityId ?? $this->entityA,
            'channel' => 'admin',
            'status' => $status,
            'accounting_status' => 'not_posted',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 1000,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'order_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);
    }

    private function orderLine(SalesOrder $order, float $quantity = 10, float $allocated = 0): SalesOrderLine
    {
        return SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'legal_entity_id' => $order->legal_entity_id,
            'product_id' => $this->product->id,
            'description' => '411 order line',
            'quantity' => $quantity,
            'quantity_allocated' => $allocated,
            'quantity_cancelled' => 0,
            'quantity_delivered' => 0,
            'quantity_invoiced' => 0,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => $quantity * 100,
        ]);
    }

    /** A completed delivery of an SO line, with the SO-line delivered mirror. */
    private function deliver(SalesOrderLine $line, float $quantity, string $status = 'done'): DeliveryOrder
    {
        $deliveryOrder = DeliveryOrder::create([
            'delivery_order_number' => 'DO-411-'.uniqid(),
            'sales_order_id' => $line->sales_order_id,
            'customer_id' => $this->customer->id,
            'status' => $status,
            'source' => 'sales_order',
            'delivery_date' => now()->toDateString(),
            'completed_at' => $status === 'done' ? now() : null,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ]);

        DeliveryOrderLine::create([
            'delivery_order_id' => $deliveryOrder->id,
            'sales_order_line_id' => $line->id,
            'product_id' => $line->product_id,
            'description' => '411 delivery line',
            'quantity_ordered' => (float) $line->quantity,
            'quantity_delivered' => $quantity,
            'quantity_invoiced' => 0,
            'unit_price' => 100,
            'line_total' => $quantity * 100,
        ]);

        if ($status === 'done') {
            $line->update(['quantity_delivered' => (float) $line->fresh()->quantity_delivered + $quantity]);
        }

        return $deliveryOrder;
    }

    /** An SO invoice for $quantity of the line, with the SO-line invoiced mirror. */
    private function invoice(SalesOrderLine $line, float $quantity, string $status = 'sent', string $lifecycle = 'posted'): Invoice
    {
        $total = $quantity * 100;
        $invoice = Invoice::create([
            'invoice_number' => 'INV-411-'.uniqid(),
            'customer_id' => $this->customer->id,
            'sales_order_id' => $line->sales_order_id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => $status,
            'lifecycle_status' => $lifecycle,
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'balance' => $total,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'sales_order_line_id' => $line->id,
            'line_type' => 'product',
            'product_id' => $this->product->id,
            'description' => '411 invoice line',
            'quantity' => $quantity,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'line_total' => $total,
        ]);

        if (! in_array($status, ['cancelled', 'voided'], true)) {
            $line->update(['quantity_invoiced' => (float) $line->fresh()->quantity_invoiced + $quantity]);
        }

        return $invoice;
    }

    /** A customer receipt applied to the invoice (payment_applications, #155). */
    private function pay(Invoice $invoice, float $amount, bool $reversed = false): Payment
    {
        $payment = Payment::create([
            'payment_number' => 'PAY-411-'.uniqid(),
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
            'method' => 'bank_transfer',
            'received_by' => $this->admin->id,
            'currency' => 'USD',
        ]);

        DB::table('payment_applications')->insert([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'applied_amount' => $amount,
            'currency_code' => 'USD',
            'reversed_at' => $reversed ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $payment;
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    private function fulfilment(SalesOrder $order, ?User $user = null): array
    {
        $response = $this->actingAs($user ?? $this->admin)->getJson("/api/v1/sales/orders/{$order->id}/fulfilment");
        $response->assertOk();

        return $response->json();
    }

    /** @return array<int, int> */
    private function stuckOrderIds(string $stage, ?User $user = null): array
    {
        $response = $this->actingAs($user ?? $this->admin)
            ->getJson('/api/v1/sales/orders?per_page=100&stuck_at='.$stage);
        $response->assertOk();

        $ids = array_map('intval', array_column($response->json('data'), 'id'));
        sort($ids);

        return $ids;
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    public function test_endpoint_returns_the_per_line_chain_with_the_documents_behind_each_stage(): void
    {
        $order = $this->salesOrder();
        $line = $this->orderLine($order, 10, 10);
        $deliveryOrder = $this->deliver($line, 6);
        $invoice = $this->invoice($line, 6);
        $payment = $this->pay($invoice, 300);
        // A reversed receipt and a cancelled invoice never count.
        $this->pay($invoice, 100, reversed: true);
        $this->invoice($line, 4, 'cancelled', 'cancelled');

        $body = $this->fulfilment($order);

        $this->assertSame($order->id, $body['sales_order_id']);
        $this->assertSame($order->sales_order_number, $body['sales_order_number']);
        $this->assertCount(1, $body['lines']);
        $chain = $body['lines'][0];

        $this->assertSame($line->id, $chain['sales_order_line_id']);
        $this->assertEquals(10, $chain['ordered']['quantity']);
        $this->assertEquals(1000, $chain['ordered']['amount']);
        $this->assertEquals(10, $chain['reserved']['quantity']);
        $this->assertEquals(6, $chain['delivered']['quantity']);
        $this->assertEquals(600, $chain['delivered']['amount']);
        $this->assertEquals(6, $chain['invoiced']['quantity']);
        $this->assertEquals(600, $chain['invoiced']['amount']);
        // 300 of a 600 invoice is paid → half of the 6 invoiced units.
        $this->assertEquals(3, $chain['paid']['quantity']);
        $this->assertEquals(300, $chain['paid']['amount']);
        $this->assertSame('deliver', $chain['stuck_at']);

        $this->assertSame([$deliveryOrder->id], array_column($chain['delivered']['delivery_orders'], 'id'));
        $this->assertSame($deliveryOrder->delivery_order_number, $chain['delivered']['delivery_orders'][0]['delivery_order_number']);
        $this->assertEquals(6, $chain['delivered']['delivery_orders'][0]['quantity']);

        $this->assertSame([$invoice->id], array_column($chain['invoiced']['invoices'], 'id'));
        $this->assertSame($invoice->invoice_number, $chain['invoiced']['invoices'][0]['invoice_number']);
        $this->assertEquals(6, $chain['invoiced']['invoices'][0]['quantity']);
        $this->assertEquals(300, $chain['invoiced']['invoices'][0]['paid_amount']);

        $this->assertSame([$payment->id], array_column($chain['paid']['payments'], 'id'));
        $this->assertSame($payment->payment_number, $chain['paid']['payments'][0]['payment_number']);
        $this->assertSame($invoice->id, $chain['paid']['payments'][0]['invoice_id']);
        $this->assertEquals(300, $chain['paid']['payments'][0]['applied_amount']);
    }

    public function test_reserved_stays_reached_once_goods_are_delivered_and_a_fully_paid_line_is_not_stuck(): void
    {
        // quantity_allocated is set at allocation time and not consumed by
        // delivery; delivered units have necessarily been reserved.
        $order = $this->salesOrder();
        $line = $this->orderLine($order, 10, 0);
        $this->deliver($line, 10);
        $invoice = $this->invoice($line, 10);
        $this->pay($invoice, 1000);

        $chain = $this->fulfilment($order)['lines'][0];
        $this->assertEquals(10, $chain['reserved']['quantity']);
        $this->assertEquals(10, $chain['paid']['quantity']);
        $this->assertNull($chain['stuck_at']);
    }

    public function test_sales_order_list_filters_orders_with_a_line_stuck_at_each_stage(): void
    {
        $reserve = $this->salesOrder();
        $this->orderLine($reserve, 10, 4);

        $deliver = $this->salesOrder();
        $this->deliver($this->orderLine($deliver, 10, 10), 6);

        $invoice = $this->salesOrder();
        $line = $this->orderLine($invoice, 10, 10);
        $this->deliver($line, 10);
        $this->invoice($line, 4);

        $pay = $this->salesOrder();
        $line = $this->orderLine($pay, 10, 10);
        $this->deliver($line, 10);
        $this->pay($this->invoice($line, 10), 500);

        // A reversed receipt leaves the line stuck at payment.
        $reversed = $this->salesOrder();
        $line = $this->orderLine($reversed, 10, 10);
        $this->deliver($line, 10);
        $this->pay($this->invoice($line, 10), 1000, reversed: true);

        $done = $this->salesOrder();
        $line = $this->orderLine($done, 10, 10);
        $this->deliver($line, 10);
        $this->pay($this->invoice($line, 10), 1000);

        // Mixed order: one complete line, one waiting for delivery.
        $mixed = $this->salesOrder();
        $line = $this->orderLine($mixed, 5, 5);
        $this->deliver($line, 5);
        $this->pay($this->invoice($line, 5), 500);
        $this->orderLine($mixed, 5, 5);

        // Draft and cancelled orders are not "stuck" in fulfilment.
        $this->orderLine($this->salesOrder('draft'), 10, 0);
        $this->orderLine($this->salesOrder('cancelled'), 10, 0);

        $sorted = function (SalesOrder ...$orders): array {
            $ids = array_map(fn (SalesOrder $o) => $o->id, $orders);
            sort($ids);

            return $ids;
        };

        $this->assertSame($sorted($reserve), $this->stuckOrderIds('reserve'));
        $this->assertSame($sorted($deliver, $mixed), $this->stuckOrderIds('deliver'));
        $this->assertSame($sorted($invoice), $this->stuckOrderIds('invoice'));
        $this->assertSame($sorted($pay, $reversed), $this->stuckOrderIds('pay'));

        $this->actingAs($this->admin)->getJson('/api/v1/sales/orders?stuck_at=bogus')->assertStatus(422);
    }

    public function test_access_follows_the_sales_order_show_route(): void
    {
        $order = $this->salesOrder();
        $this->orderLine($order, 10, 4);
        $other = $this->salesOrder('confirmed', $this->entityB);
        $this->orderLine($other, 10, 4);

        // Accountant holds no sales.view — same 403 as GET /sales/orders/{id}.
        $accountant = $this->makeUser('accountant');
        $this->actingAs($accountant)->getJson("/api/v1/sales/orders/{$order->id}")->assertForbidden();
        $this->actingAs($accountant)->getJson("/api/v1/sales/orders/{$order->id}/fulfilment")->assertForbidden();

        // A user scoped to entity A cannot read entity B's order chain.
        $scoped = $this->makeUser('admin', [$this->entityA]);
        $this->actingAs($scoped)->getJson("/api/v1/sales/orders/{$other->id}")->assertNotFound();
        $this->actingAs($scoped)->getJson("/api/v1/sales/orders/{$other->id}/fulfilment")->assertNotFound();
        $this->assertSame($order->id, $this->fulfilment($order, $scoped)['sales_order_id']);

        // ...and the stuck filter is entity-scoped like the rest of the list.
        $this->assertSame([$order->id], $this->stuckOrderIds('reserve', $scoped));
    }
}

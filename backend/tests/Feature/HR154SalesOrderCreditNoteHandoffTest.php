<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #154 — cancelling an invoiced order told the user to "use credit
 * notes instead" while no Sales path could create one, and a credit note had
 * no lineage back to the sales order.
 */
class HR154SalesOrderCreditNoteHandoffTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '11111111-0000-9999-8888-777777777777',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'sales_orders', 'sales_order_lines', 'sales_invoice_links',
            'customer_credit_notes', 'customer_credit_note_lines', 'invoices',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'HR154 User',
            'email' => 'hr154-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('admin');

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
    }

    private function invoicedOrder(): SalesOrder
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-HR154-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'status' => 'invoiced',
            'created_by' => $this->user->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'HR154 line',
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 50,
            'tax_rate' => 10,
        ]);

        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'posted',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->user->id,
        ]);

        // #372 (O3): the credit note is built from the invoice's lines, so the
        // fixture must carry the line the order was invoiced with. The
        // assertions below (quantity, total 110) stay exactly the same.
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'HR154 line',
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 50,
            'tax_rate' => 10,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        $invoice->calculateTotals();

        SalesInvoiceLink::create([
            'sales_order_id' => $order->id,
            'invoice_id' => $invoice->id,
        ]);

        return $order;
    }

    public function test_credit_note_is_created_from_an_invoiced_order_with_full_lineage(): void
    {
        $order = $this->invoicedOrder();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/sales/orders/'.$order->id.'/credit-note', [
                'reason' => 'Customer returned the goods',
            ]);

        $response->assertStatus(201);

        $creditNote = CustomerCreditNote::find($response->json('credit_note.id'));
        $this->assertNotNull($creditNote);
        $this->assertSame('draft', $creditNote->status);
        $this->assertSame($order->id, (int) $creditNote->sales_order_id, 'The credit note must link back to the order');
        $this->assertNotNull($creditNote->invoice_id, 'The credit note must link to the order invoice');
        $this->assertSame(110.0, round((float) $creditNote->total_amount, 2));
        $this->assertSame(1, $creditNote->lines()->count());
    }

    public function test_cancel_error_points_at_the_now_reachable_credit_note_action(): void
    {
        $order = $this->invoicedOrder();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/sales/orders/'.$order->id.'/cancel', [
                'reason' => 'Customer changed their mind',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('credit note', strtolower((string) $response->json('message')));
    }

    public function test_non_invoiced_orders_cannot_be_credited(): void
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-HR154-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'status' => 'confirmed',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales/orders/'.$order->id.'/credit-note')
            ->assertStatus(422);
    }

    public function test_an_invoiced_order_without_a_linked_invoice_is_rejected(): void
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-HR154-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'status' => 'invoiced',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales/orders/'.$order->id.'/credit-note')
            ->assertStatus(422);
    }
}

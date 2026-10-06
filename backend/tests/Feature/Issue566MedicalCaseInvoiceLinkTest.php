<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #566 (MC4): `store()`, `update()` and `invoice()` accepted any
 * `invoice_id` that existed — another customer's or a cancelled one.
 * `invoice_item_id` was not checked to belong to that invoice or product, and
 * `invoiced_qty` was not checked against the invoice line quantity.
 *
 * Acceptance: the invoice belongs to the case customer and is not cancelled;
 * each case line maps to an invoice line of the same product; the invoiced
 * quantity across cases ≤ the line quantity.
 *
 * Real HTTP through the router (POST /api/v1/medical-cases,
 * PUT /api/v1/medical-cases/{id}, POST /api/v1/medical-cases/{id}/invoice).
 */
class Issue566MedicalCaseInvoiceLinkTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Customer $otherCustomer;

    private Product $product;

    private Product $otherProduct;

    private SalesOrderLine $salesOrderLine;

    private Invoice $invoice;

    private InvoiceLine $invoiceLine;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '56656666-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'sales_order_lines', 'invoices', 'invoice_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'Issue566 User',
            'email' => 'issue566-'.uniqid().'@test.local',
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
        $this->otherCustomer = Customer::factory()->create();
        $this->product = Product::factory()->create();
        $this->otherProduct = Product::factory()->create();

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-566-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 566 line',
            'product_id' => $this->product->id,
            'quantity' => 100,
            'unit_price' => 25,
        ]);

        $this->invoice = $this->makeInvoice($this->customer, 'posted');
        $this->invoiceLine = $this->makeInvoiceLine($this->invoice, $this->product, 10);
    }

    private function makeInvoice(Customer $customer, string $status): Invoice
    {
        return Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => $status,
            'created_by' => $this->user->id,
        ]);
    }

    private function makeInvoiceLine(Invoice $invoice, Product $product, float $quantity): InvoiceLine
    {
        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'Issue 566 invoice line',
            'quantity' => $quantity,
            'unit_price' => 25,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => 25 * $quantity,
        ]);
    }

    private function deliveredCase(float $delivered = 6): MedicalCaseItem
    {
        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'status' => 'delivered',
            'created_by' => $this->user->id,
        ]);

        return MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine->id,
            'product_id' => $this->product->id,
            'allocated_qty' => $delivered,
            'delivered_qty' => $delivered,
            'unit_price' => 25,
        ]);
    }

    private function invoiceCase(MedicalCaseItem $item, int $invoiceId, ?int $invoiceLineId, float $qty)
    {
        $line = ['id' => $item->id, 'invoiced_qty' => $qty];

        if ($invoiceLineId !== null) {
            $line['invoice_item_id'] = $invoiceLineId;
        }

        return $this->actingAs($this->user)->postJson(
            '/api/v1/medical-cases/'.$item->medical_case_id.'/invoice',
            ['invoice_id' => $invoiceId, 'items' => [$line]],
        );
    }

    public function test_invoice_rejects_another_customers_invoice(): void
    {
        $foreignInvoice = $this->makeInvoice($this->otherCustomer, 'posted');
        $foreignLine = $this->makeInvoiceLine($foreignInvoice, $this->product, 10);
        $item = $this->deliveredCase();

        $response = $this->invoiceCase($item, $foreignInvoice->id, $foreignLine->id, 6);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['invoice_id']);

        $this->assertNull($item->medicalCase->fresh()->invoice_id);
        $this->assertSame('delivered', $item->medicalCase->fresh()->status);
    }

    public function test_invoice_rejects_a_cancelled_invoice(): void
    {
        $cancelled = $this->makeInvoice($this->customer, 'cancelled');
        $line = $this->makeInvoiceLine($cancelled, $this->product, 10);
        $item = $this->deliveredCase();

        $response = $this->invoiceCase($item, $cancelled->id, $line->id, 6);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['invoice_id']);
        $this->assertSame('delivered', $item->medicalCase->fresh()->status);
    }

    public function test_invoice_requires_an_invoice_line_per_case_line(): void
    {
        $item = $this->deliveredCase();

        $response = $this->invoiceCase($item, $this->invoice->id, null, 6);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.invoice_item_id']);
    }

    public function test_invoice_rejects_a_line_of_a_different_invoice(): void
    {
        $otherInvoice = $this->makeInvoice($this->customer, 'posted');
        $otherLine = $this->makeInvoiceLine($otherInvoice, $this->product, 10);
        $item = $this->deliveredCase();

        $response = $this->invoiceCase($item, $this->invoice->id, $otherLine->id, 6);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.invoice_item_id']);
        $this->assertNull($item->fresh()->invoice_item_id);
    }

    public function test_invoice_rejects_a_line_of_a_different_product(): void
    {
        $wrongProductLine = $this->makeInvoiceLine($this->invoice, $this->otherProduct, 10);
        $item = $this->deliveredCase();

        $response = $this->invoiceCase($item, $this->invoice->id, $wrongProductLine->id, 6);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.invoice_item_id']);
    }

    public function test_invoice_rejects_over_invoicing_an_invoice_line_across_cases(): void
    {
        // Invoice line quantity is 10. The first case consumes 6 of it.
        $first = $this->deliveredCase(6);
        $this->invoiceCase($first, $this->invoice->id, $this->invoiceLine->id, 6)->assertOk();

        // The second case may not consume another 6 of the same 10.
        $second = $this->deliveredCase(6);
        $response = $this->invoiceCase($second, $this->invoice->id, $this->invoiceLine->id, 6);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.invoiced_qty']);

        $this->assertSame(0.0, (float) $second->fresh()->invoiced_qty);
        $this->assertSame('delivered', $second->medicalCase->fresh()->status);

        // The remaining 4 still fit.
        $this->invoiceCase($second, $this->invoice->id, $this->invoiceLine->id, 4)->assertOk();
    }

    public function test_invoice_rejects_invoiced_quantity_above_delivered_quantity_as_422(): void
    {
        $item = $this->deliveredCase(3);

        $response = $this->invoiceCase($item, $this->invoice->id, $this->invoiceLine->id, 5);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.invoiced_qty']);
    }

    public function test_invoice_links_the_case_line_to_a_matching_invoice_line(): void
    {
        $item = $this->deliveredCase(6);

        $response = $this->invoiceCase($item, $this->invoice->id, $this->invoiceLine->id, 6);

        $response->assertOk();

        $item->refresh();
        $this->assertSame($this->invoiceLine->id, (int) $item->invoice_item_id);
        $this->assertSame(6.0, (float) $item->invoiced_qty);
        $this->assertSame($this->invoice->id, (int) $item->medicalCase->fresh()->invoice_id);
        $this->assertSame('invoiced', $item->medicalCase->fresh()->status);
    }

    public function test_store_rejects_another_customers_invoice(): void
    {
        $foreignInvoice = $this->makeInvoice($this->otherCustomer, 'posted');

        $response = $this->actingAs($this->user)->postJson('/api/v1/medical-cases', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $foreignInvoice->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['invoice_id']);
        $this->assertSame(0, MedicalCase::count());
    }

    public function test_store_rejects_a_cancelled_invoice(): void
    {
        $cancelled = $this->makeInvoice($this->customer, 'cancelled');

        $response = $this->actingAs($this->user)->postJson('/api/v1/medical-cases', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $cancelled->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['invoice_id']);
    }

    public function test_store_accepts_the_customers_own_invoice(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/medical-cases', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $this->invoice->id,
        ]);

        $response->assertCreated();
    }

    public function test_update_rejects_another_customers_invoice(): void
    {
        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
        $foreignInvoice = $this->makeInvoice($this->otherCustomer, 'posted');

        $response = $this->actingAs($this->user)->putJson('/api/v1/medical-cases/'.$case->id, [
            'invoice_id' => $foreignInvoice->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['invoice_id']);
        $this->assertNull($case->fresh()->invoice_id);
    }
}

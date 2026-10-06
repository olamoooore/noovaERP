<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteLine;
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
 * Issue #574 (MC10): the hospital/doctor performance report valued a case as
 * invoiced_qty × the case's own unit_price, ignoring the linked invoice line's
 * net amount, discounts, credit notes and FOC lines, and summing across
 * currencies.
 *
 * Acceptance: the value comes from the linked invoice lines (net of discount,
 * minus credit notes), converted to base currency; FOC units are reported
 * separately (count and list value).
 *
 * Real HTTP through the router (GET /api/v1/medical-cases/reports/performance).
 */
class Issue574MedicalCaseReportInvoiceValueTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    private Product $focProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '57457457-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'sales_order_lines', 'invoices', 'invoice_lines',
            'customer_credit_notes', 'customer_credit_note_lines', 'currencies',
        ]);

        foreach (['USD' => 'US Dollar', 'EUR' => 'Euro'] as $code => $name) {
            DB::table('currencies')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'symbol' => $code, 'decimal_places' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'Issue574 User',
            'email' => 'issue574-'.uniqid().'@test.local',
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

        $this->customer = Customer::factory()->create(['name' => 'Hospital 574']);
        $this->product = Product::factory()->create(['price' => 100]);
        $this->focProduct = Product::factory()->create(['price' => 120]);
    }

    private function salesOrderLine(Product $product, float $unitPrice): SalesOrderLine
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-574-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);

        return SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 574 line',
            'product_id' => $product->id,
            'quantity' => 100,
            'unit_price' => $unitPrice,
        ]);
    }

    private function eurInvoiceLine(float $qty, float $unitPrice, float $discount, float $taxRate): InvoiceLine
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'posted',
            'currency_code' => 'EUR',
            'base_currency_code' => 'USD',
            'exchange_rate' => 2,
            'created_by' => $this->user->id,
        ]);

        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'description' => 'Issue 574 invoice line',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'tax_inclusive' => false,
            'discount_amount' => $discount,
        ]);
    }

    private function postedCreditNoteFor(InvoiceLine $line, float $qty, float $unitPrice): void
    {
        $creditNote = CustomerCreditNote::create([
            'credit_note_number' => 'CN-574-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'invoice_id' => $line->invoice_id,
            'credit_note_date' => '2026-09-20',
            'status' => 'posted',
            'subtotal' => $qty * $unitPrice,
            'tax_amount' => 0,
            'total_amount' => $qty * $unitPrice,
            'currency_code' => 'EUR',
            'exchange_rate' => 2,
            'base_currency_code' => 'USD',
            'created_by' => $this->user->id,
        ]);

        CustomerCreditNoteLine::create([
            'credit_note_id' => $creditNote->id,
            'invoice_line_id' => $line->id,
            'product_id' => $this->product->id,
            'description' => 'Issue 574 credit',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);
    }

    private function report(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->getJson(
            '/api/v1/medical-cases/reports/performance?from_date=2026-09-01&to_date=2026-09-30'
        );
    }

    public function test_the_value_is_the_linked_invoice_line_net_minus_credit_notes_in_base_currency(): void
    {
        // Invoice line: 10 × 90 EUR − 50 discount = 850 net (tax excluded).
        $invoiceLine = $this->eurInvoiceLine(10, 90, 50, 10);
        // A posted credit note returns 1 unit (90 EUR net) of that line.
        $this->postedCreditNoteFor($invoiceLine, 1, 90);

        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'case_date' => '2026-09-10',
            'invoice_id' => $invoiceLine->invoice_id,
            'status' => 'invoiced',
            'created_by' => $this->user->id,
        ]);

        // The case carries 4 of the line's 10 units, at a (stale) typed price.
        MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine($this->product, 100)->id,
            'invoice_item_id' => $invoiceLine->id,
            'product_id' => $this->product->id,
            'allocated_qty' => 4,
            'delivered_qty' => 4,
            'invoiced_qty' => 4,
            'unit_price' => 100,
        ]);

        $response = $this->report();
        $response->assertOk();

        // Case share 4/10: (850 − 90) × 0.4 = 304 EUR × 2 = 608 USD — not 4 × 100.
        $total = collect($response->json('data.hospital_totals'))->firstWhere('customer_id', $this->customer->id);
        $this->assertNotNull($total);
        $this->assertEqualsWithDelta(608.0, (float) $total['invoiced_value'], 0.001);

        $month = collect($response->json('data.by_hospital'))->firstWhere('customer_id', $this->customer->id);
        $this->assertEqualsWithDelta(608.0, (float) $month['invoiced_value'], 0.001);
    }

    public function test_foc_units_are_reported_separately_with_their_list_value(): void
    {
        $invoiceLine = $this->eurInvoiceLine(2, 50, 0, 0);

        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'case_date' => '2026-09-12',
            'invoice_id' => $invoiceLine->invoice_id,
            'status' => 'invoiced',
            'created_by' => $this->user->id,
        ]);

        MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine($this->product, 50)->id,
            'invoice_item_id' => $invoiceLine->id,
            'product_id' => $this->product->id,
            'allocated_qty' => 2,
            'delivered_qty' => 2,
            'invoiced_qty' => 2,
            'unit_price' => 50,
        ]);

        // FOC line: 3 units redeemed free; the product's list price is 120.
        MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine($this->focProduct, 0)->id,
            'product_id' => $this->focProduct->id,
            'allocated_qty' => 3,
            'delivered_qty' => 3,
            'invoiced_qty' => 0,
            'unit_price' => 0,
            'is_foc' => true,
        ]);

        $response = $this->report();
        $response->assertOk();

        $total = collect($response->json('data.hospital_totals'))->firstWhere('customer_id', $this->customer->id);
        $this->assertEqualsWithDelta(200.0, (float) $total['invoiced_value'], 0.001, '2 × 50 EUR × 2 = 200 USD; FOC adds nothing.');
        $this->assertArrayHasKey('foc_units', $total);
        $this->assertEqualsWithDelta(3.0, (float) $total['foc_units'], 0.0001);
        $this->assertEqualsWithDelta(360.0, (float) $total['foc_list_value'], 0.001);
    }
}

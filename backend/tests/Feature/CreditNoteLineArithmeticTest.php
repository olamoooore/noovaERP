<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #512 (CN3) — credit note lines ignored the source line's discount and
 * tax mode: totals were qty × price with tax always added on top, so a full
 * credit of a discounted line over-credited (1,000 instead of 900) and a tax-
 * inclusive line was taxed twice (106 credited as 106 + 6.36). Document-level
 * discount was dropped entirely.
 *
 * Contract: line totals use the same arithmetic as invoices
 * (InvoiceLine::computeLineTotals → TaxEngineService::calculate) with
 * discount_amount/tax_inclusive carried over; the document discount is
 * pro-rated; and a FULL credit equals the invoice total exactly.
 */
class CreditNoteLineArithmeticTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '88888888-7777-6666-5555-444444444444',
        ]));
        $this->bindTenantContext($tenant->id);

        $this->assertTenantSchemaReady([
            'invoices',
            'invoice_lines',
            'customers',
            'customer_credit_notes',
            'customer_credit_note_lines',
            'chart_of_accounts',
            'account_mappings',
            'journal_entries',
        ]);

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-CN3', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-CN3', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-CN3', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    private function invoiceWithLines(array $lineSpecs, float $docDiscount = 0.0): Invoice
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => $docDiscount,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->user->id,
        ]);

        foreach ($lineSpecs as $spec) {
            InvoiceLine::create(array_merge([
                'invoice_id' => $invoice->id,
                'description' => 'Line',
                'tax_inclusive' => false,
                'discount_amount' => 0,
            ], $spec));
        }

        $invoice->calculateTotals();

        return $invoice->fresh();
    }

    private function fullCreditOf(Invoice $invoice): array
    {
        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/customers/credit-notes',
            [
                'customer_id' => $this->customer->id,
                'invoice_id' => $invoice->id,
                'credit_note_date' => now()->toDateString(),
                'reason' => 'CN3 arithmetic',
                'lines' => $invoice->lines->map(fn (InvoiceLine $line) => [
                    'invoice_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'tax_rate' => $line->tax_rate,
                    'discount_amount' => $line->discount_amount,
                    'tax_inclusive' => $line->tax_inclusive,
                    'account_id' => $line->gl_account_id,
                ])->values()->all(),
            ],
        );

        $response->assertCreated();

        return $response->json('data');
    }

    public function test_full_credit_of_discounted_line_equals_invoice_total(): void
    {
        // 10 × 100 with a 100 discount → invoiced at 900.
        $invoice = $this->invoiceWithLines([
            ['description' => 'Widget', 'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 0, 'discount_amount' => 100],
        ]);

        $this->assertEqualsWithDelta(900.0, (float) $invoice->total, 0.01, 'invoice fixture must be 900');

        $cn = $this->fullCreditOf($invoice);

        $this->assertEqualsWithDelta(900.0, (float) $cn['subtotal'], 0.01, 'CN subtotal must net the line discount');
        $this->assertEqualsWithDelta((float) $invoice->total, (float) $cn['total_amount'], 0.01, 'full credit must equal the invoice total');
    }

    public function test_full_credit_of_tax_inclusive_line_equals_invoice_total(): void
    {
        // Priced 106 inclusive of 6% → invoiced at 100 + 6.
        $invoice = $this->invoiceWithLines([
            ['description' => 'Inclusive item', 'quantity' => 1, 'unit_price' => 106, 'tax_rate' => 6, 'tax_inclusive' => true],
        ]);

        $this->assertEqualsWithDelta(106.0, (float) $invoice->total, 0.01, 'invoice fixture must be 106');

        $cn = $this->fullCreditOf($invoice);

        $this->assertEqualsWithDelta(100.0, (float) $cn['subtotal'], 0.01, 'CN subtotal must extract the inclusive tax');
        $this->assertEqualsWithDelta(6.0, (float) $cn['tax_amount'], 0.01, 'CN tax must be the extracted 6, not 6.36 on top');
        $this->assertEqualsWithDelta(106.0, (float) $cn['total_amount'], 0.01, 'full credit must equal the invoice total');
    }

    public function test_document_discount_is_carried_on_a_full_credit(): void
    {
        // Invoice: line 1,000 + 6% tax, document discount 50 →
        // subtotal 1000, tax 60×(950/1000)=57, total 1,007.
        $invoice = $this->invoiceWithLines(
            [['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 6]],
            50.0,
        );

        $this->assertEqualsWithDelta(1007.0, (float) $invoice->total, 0.01, 'invoice fixture must be 1007');

        $cn = $this->fullCreditOf($invoice);

        $this->assertEqualsWithDelta(50.0, (float) $cn['discount_amount'], 0.01, 'document discount must carry to the CN');
        $this->assertEqualsWithDelta(57.0, (float) $cn['tax_amount'], 0.01, 'tax must be prorated after the document discount');
        $this->assertEqualsWithDelta((float) $invoice->total, (float) $cn['total_amount'], 0.01, 'full credit must equal the invoice total');
    }

    public function test_document_discount_is_prorated_on_a_partial_credit(): void
    {
        // Two 500 lines at 6% (tax 30 each), document discount 50 → total 1007.
        // Crediting only the first line takes half the discount: 500 − 25 + 28.50.
        $invoice = $this->invoiceWithLines([
            ['description' => 'Part A', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 6],
            ['description' => 'Part B', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 6],
        ], 50.0);

        $this->assertEqualsWithDelta(1007.0, (float) $invoice->total, 0.01);

        $lineA = $invoice->lines->first();

        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/customers/credit-notes',
            [
                'customer_id' => $this->customer->id,
                'invoice_id' => $invoice->id,
                'credit_note_date' => now()->toDateString(),
                'reason' => 'CN3 partial',
                'lines' => [[
                    'invoice_line_id' => $lineA->id,
                    'description' => $lineA->description,
                    'quantity' => $lineA->quantity,
                    'unit_price' => $lineA->unit_price,
                    'tax_rate' => $lineA->tax_rate,
                    'discount_amount' => $lineA->discount_amount,
                    'tax_inclusive' => $lineA->tax_inclusive,
                ]],
            ],
        );
        $response->assertCreated();
        $cn = $response->json('data');

        $this->assertEqualsWithDelta(500.0, (float) $cn['subtotal'], 0.01);
        $this->assertEqualsWithDelta(25.0, (float) $cn['discount_amount'], 0.01, 'discount prorated by credited share (500/1000)');
        $this->assertEqualsWithDelta(28.5, (float) $cn['tax_amount'], 0.01, 'line tax prorated after the prorated discount');
        $this->assertEqualsWithDelta(503.5, (float) $cn['total_amount'], 0.01);
    }

    public function test_posted_full_credit_reverses_the_right_tax(): void
    {
        $invoice = $this->invoiceWithLines([
            ['description' => 'Inclusive item', 'quantity' => 1, 'unit_price' => 106, 'tax_rate' => 6, 'tax_inclusive' => true],
        ]);

        $cn = $this->fullCreditOf($invoice);

        $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/customers/credit-notes/'.$cn['id'].'/post')
            ->assertOk();

        // The GL must reverse exactly the invoiced tax (6.00), and the entry
        // must balance.
        $entryId = (int) DB::table('customer_credit_notes')->where('id', $cn['id'])->value('journal_entry_id');
        $this->assertNotNull($entryId);

        // The GL must reverse exactly the invoiced tax (6.00) under the
        // ORIGINAL tax code — asserted via the code, not a hand-seeded
        // account, because posting resolves the tenant's canonical mapping.
        $taxLine = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $entryId)
            ->where('tax_code', 'SG-GST-6')
            ->first();
        $this->assertNotNull($taxLine, 'tax reversal line must carry the original tax code');
        $this->assertEqualsWithDelta(6.0, (float) $taxLine->debit, 0.01, 'tax reversal must equal the invoiced tax');

        $sums = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $entryId)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();
        $this->assertEqualsWithDelta((float) $sums->d, (float) $sums->c, 0.01, 'JE must balance');
    }
}

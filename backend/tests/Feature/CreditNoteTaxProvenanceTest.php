<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\TaxDeterminationResult;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #514 (CN5) — a credit note's tax identity is decided in two places that
 * can disagree. In postCreditNote each determination line carried the ORIGINAL
 * invoice line's rate but the AMOUNT was computed from the credit note line's
 * own rate, so the tax code and rate said one thing while the booked tax
 * amount said another. Worse, `tax_rate` is optional on the API and defaulted to
 * 0, so a credit note raised without a rate booked no tax at all: no
 * determination was created, no tax line was posted, and revenue was debited
 * for the full gross amount — the output tax on the original invoice was never
 * reversed, overstating SST/GST payable on the return.
 *
 * Contract asserted here:
 *  - with invoice_line_id set, the rate comes from the original invoice line's
 *    determination server-side, and a conflicting client rate is rejected;
 *  - without invoice_line_id the rate is resolved through TaxEngineService
 *    (an unconfigured tenant still resolves to its own "no tax" answer, never a
 *    silent 0 with a fabricated code);
 *  - a credit note raised without tax_rate against a 6% line reverses 6% of
 *    output tax and debits revenue net.
 */
class CreditNoteTaxProvenanceTest extends TenantTestCase
{
    private string $tenantId = '88888888-7777-6666-5555-444444444444';

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => $this->tenantId]));
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

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-CN5', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-CN5', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-CN5', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    private function invoiceWithTaxedLine(float $quantity = 1.0, float $unitPrice = 100.0, float $taxRate = 6.0): array
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->user->id,
        ]);

        $line = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Taxed widget',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        $invoice->calculateTotals();

        return [$invoice->fresh(), $line];
    }

    /**
     * @return array{0: \Illuminate\Testing\TestResponse, 1: CustomerCreditNote|null}
     */
    private function storeCreditNote(Invoice $invoice, array $lines)
    {
        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/customers/credit-notes',
            [
                'customer_id' => $this->customer->id,
                'invoice_id' => $invoice->id,
                'credit_note_date' => now()->toDateString(),
                'reason' => 'CN5 tax provenance',
                'lines' => $lines,
            ],
        );

        $id = $response->json('data.id');

        return [$response, $id ? CustomerCreditNote::find($id) : null];
    }

    private function linkedLinePayload(InvoiceLine $line, float $quantity, ?float $taxRate = null): array
    {
        $payload = [
            'invoice_line_id' => $line->id,
            'description' => $line->description,
            'quantity' => $quantity,
            'unit_price' => $line->unit_price,
        ];

        if ($taxRate !== null) {
            $payload['tax_rate'] = $taxRate;
        }

        return $payload;
    }

    public function test_credit_note_without_tax_rate_reverses_the_invoiced_tax(): void
    {
        [$invoice, $line] = $this->invoiceWithTaxedLine();
        $this->assertEqualsWithDelta(106.0, (float) $invoice->total, 0.01, 'invoice fixture must be 100 + 6% tax');

        // No tax_rate in the payload: the rate must be inherited from the
        // invoiced line, not defaulted to 0.
        [$response, $creditNote] = $this->storeCreditNote($invoice, [$this->linkedLinePayload($line, 1.0)]);

        $response->assertCreated();
        $this->assertEqualsWithDelta(6.0, (float) $creditNote->tax_amount, 0.01, 'the credit note must book the invoiced 6% tax');
        $this->assertEqualsWithDelta(106.0, (float) $creditNote->total_amount, 0.01, 'a full credit of a 6% line must equal the invoice total');

        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post")
            ->assertOk();

        $entryId = (int) DB::table('customer_credit_notes')->where('id', $creditNote->id)->value('journal_entry_id');
        $this->assertNotNull($entryId, 'a posted credit note must carry a journal entry');

        $lines = DB::table('journal_entry_lines')->where('journal_entry_id', $entryId)->get();

        $taxLeg = $lines->firstWhere('tax_code', 'SG-GST-6');
        $this->assertNotNull($taxLeg, 'the reversal must post the original tax code, got: '.$lines->pluck('tax_code')->implode(','));
        $this->assertEqualsWithDelta(6.0, (float) $taxLeg->debit, 0.01, 'output tax must be reversed at the invoiced rate');
        $this->assertEqualsWithDelta(0.0, (float) $taxLeg->credit, 0.01, 'the tax reversal debits the payable (credit note side)');

        $revenue = $lines->firstWhere('tax_code', null);
        $this->assertNotNull($revenue);
        $this->assertEqualsWithDelta(100.0, (float) $revenue->debit, 0.01, 'revenue must be reversed NET of tax, not gross');

        $sums = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $entryId)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();
        $this->assertEqualsWithDelta((float) $sums->d, (float) $sums->c, 0.01, 'the credit note journal entry must balance');

        $detail = DB::table('tax_transaction_details')
            ->where('source_document_type', 'credit_note')
            ->where('source_document_id', $creditNote->id)
            ->first();
        $this->assertNotNull($detail, 'the reversal must reach the statutory return as a credit_note row');
        $this->assertEqualsWithDelta(6.0, (float) $detail->tax_amount, 0.01, 'the reversal must be recorded at the invoiced rate');
        $this->assertSame('SG-GST-6', $detail->tax_code, 'the reversal must carry the invoice tax code');
    }

    public function test_client_tax_rate_that_conflicts_with_the_invoiced_line_is_rejected(): void
    {
        [$invoice, $line] = $this->invoiceWithTaxedLine();

        // The invoiced line is 6%; a client asking for 9% must not silently
        // re-rate the reversal.
        [$response, $creditNote] = $this->storeCreditNote($invoice, [$this->linkedLinePayload($line, 1.0, 9.0)]);

        $response->assertStatus(422);
        $this->assertNull($creditNote, 'a rejected credit note must not be created');
        $this->assertStringContainsString('tax rate', strtolower((string) $response->json('error')));
        $this->assertSame(0, CustomerCreditNote::count(), 'no credit note may be persisted for a conflicting rate');
    }

    public function test_posted_tax_follows_the_invoice_determination_when_the_stored_rate_drifts(): void
    {
        // Invoice of 2 x 100 at 6% = 212, credited one unit at a time so the
        // document total stays inside the invoice.
        [$invoice, $line] = $this->invoiceWithTaxedLine(2.0);

        // The invoice's own determination settled the line at 9%, even though
        // the invoice line column says 6%.
        TaxDeterminationResult::create([
            'tenant_id' => $this->tenantId,
            'result_version' => 1,
            'jurisdiction' => 'SG',
            'tax_lines' => [[
                'source_line_id' => $line->id,
                'tax_code' => 'SG-GST-9',
                'tax_rate' => 9,
                'treatment' => 'standard_rated',
                'tax_id' => null,
                'taxable_base' => 200.0,
                'tax_amount' => 18.0,
            ]],
            'result_status' => 'DETERMINED',
            'source_record_type' => 'invoice',
            'source_record_id' => $invoice->id,
        ]);

        [$response, $creditNote] = $this->storeCreditNote($invoice, [$this->linkedLinePayload($line, 1.0, 9.0)]);
        $response->assertCreated();
        $this->assertEqualsWithDelta(9.0, (float) $creditNote->tax_amount, 0.01, 'the rate must come from the invoice determination');

        // A drifted draft (legacy row, or a direct model write) carries a rate
        // that disagrees with the invoice determination. The posting must take
        // BOTH the rate and the amount from the determination.
        $cnLine = $creditNote->lines()->first();
        $cnLine->update(['tax_rate' => 6]);

        $postResponse = $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post");
        $postResponse->assertOk('credit note post failed: '.substr((string) json_encode($postResponse->json()), 0, 500));

        $entryId = (int) DB::table('customer_credit_notes')->where('id', $creditNote->id)->value('journal_entry_id');
        $this->assertNotNull($entryId);

        $taxLeg = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $entryId)
            ->where('tax_code', 'SG-GST-9')
            ->first();
        $this->assertNotNull($taxLeg, 'the reversal must carry the invoice determination tax code');

        // Booked tax was 9.00 (100 net x 9%), so the entry must reverse 9.00 —
        // not 6.00 recomputed from the drifted line rate.
        $this->assertEqualsWithDelta(9.0, (float) $taxLeg->debit, 0.01, 'the tax amount must be computed with the determination rate');

        $sums = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $entryId)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();
        $this->assertEqualsWithDelta((float) $sums->d, (float) $sums->c, 0.01, 'the credit note journal entry must balance');
    }

    public function test_unlinked_line_resolves_its_rate_through_the_tax_engine(): void
    {
        $rule = Tax::create([
            'name' => 'SG GST 9% (output)',
            'code' => 'SG-GST-9-CN5',
            'type' => 'gst',
            'regime' => 'GST',
            'jurisdiction' => 'SG',
            'scope' => 'output',
            'treatment' => 'standard_rated',
            'rate' => 9,
            'is_active' => true,
        ]);

        Setting::setValue('tax', [
            'enabled' => true,
            'pricing_mode' => 'exclusive',
            'default_tax_id' => $rule->id,
            'jurisdiction' => 'SG',
        ]);

        [$invoice] = $this->invoiceWithTaxedLine(2.0);

        // No invoice_line_id and no tax_rate: the rate must come from the tax
        // engine (tenant default), not from a silent 0.
        [$response, $creditNote] = $this->storeCreditNote($invoice, [[
            'description' => 'Unlinked advisory line',
            'quantity' => 1,
            'unit_price' => 50,
        ]]);

        $response->assertCreated();

        $cnLine = $creditNote->lines()->first();
        $this->assertEqualsWithDelta(9.0, (float) $cnLine->tax_rate, 0.01, 'an unlinked line must resolve its rate through TaxEngineService');
        $this->assertEqualsWithDelta(4.5, (float) $cnLine->tax_amount, 0.01, 'the resolved rate must drive the line tax amount');
        $this->assertEqualsWithDelta(4.5, (float) $creditNote->tax_amount, 0.01, 'the header must book the resolved line tax');
    }
}

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
 * Issue #518 (CN9) — PUT /credit-notes/{id} accepted header fields only, so a
 * draft's lines, quantities and prices could not be corrected and its totals
 * were never recalculated; the user had to delete and recreate the draft.
 *
 * Contract: a DRAFT update accepts lines (replace-all), recalculates totals
 * through the same line-calculation path used at create time (CN3 arithmetic,
 * CN5 tax-rate provenance, CN2 customer pairing), and a POSTED credit note
 * rejects line edits.
 */
class CreditNoteDraftLineEditTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '51851851-8518-4518-8518-518518518518',
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

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-CN9', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-CN9', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-CN9', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    /**
     * Invoice: Widget 10 × 100 @ 6% with a 100 line discount (net 900, tax 54)
     * and Gadget 5 × 200 @ 6% (net 1000, tax 60) → total 2,014.
     */
    private function invoice(): Invoice
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

        InvoiceLine::create([
            'invoice_id' => $invoice->id, 'description' => 'Widget',
            'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 6,
            'tax_inclusive' => false, 'discount_amount' => 100,
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->id, 'description' => 'Gadget',
            'quantity' => 5, 'unit_price' => 200, 'tax_rate' => 6,
            'tax_inclusive' => false, 'discount_amount' => 0,
        ]);

        $invoice->calculateTotals();

        return $invoice->fresh(['lines']);
    }

    private function draftFor(Invoice $invoice): int
    {
        $widget = $invoice->lines->firstWhere('description', 'Widget');

        $response = $this->actingAs($this->user)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'CN9 draft',
            'lines' => [[
                'invoice_line_id' => $widget->id,
                'description' => 'Widget',
                'quantity' => 1,
                'unit_price' => 100,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        return (int) $response->json('id');
    }

    public function test_draft_lines_can_be_replaced_and_totals_recalculated(): void
    {
        $invoice = $this->invoice();
        $widget = $invoice->lines->firstWhere('description', 'Widget');
        $gadget = $invoice->lines->firstWhere('description', 'Gadget');
        $cnId = $this->draftFor($invoice);

        // The initial draft: 1 × 100 @ 6% → 100 + 6.
        $this->assertEqualsWithDelta(106.0, (float) DB::table('customer_credit_notes')->where('id', $cnId)->value('total_amount'), 0.01);

        $response = $this->actingAs($this->user)->putJson("/api/v1/accounting/customers/credit-notes/{$cnId}", [
            'reason' => 'CN9 corrected quantities',
            'lines' => [
                // Corrected quantity, with a line discount: 3 × 100 − 30 = 270, tax 16.20
                ['invoice_line_id' => $widget->id, 'description' => 'Widget', 'quantity' => 3, 'unit_price' => 100, 'discount_amount' => 30],
                // An added line: 2 × 200 = 400, tax 24 (rate inherited from the invoice line)
                ['invoice_line_id' => $gadget->id, 'description' => 'Gadget', 'quantity' => 2, 'unit_price' => 200],
            ],
        ]);
        $response->assertOk();

        $cn = DB::table('customer_credit_notes')->where('id', $cnId)->first();
        $this->assertSame('CN9 corrected quantities', $cn->reason);
        $this->assertSame('draft', $cn->status);
        $this->assertEqualsWithDelta(670.0, (float) $cn->subtotal, 0.01, 'subtotal = 270 + 400');
        $this->assertEqualsWithDelta(40.2, (float) $cn->tax_amount, 0.01, 'tax = 16.20 + 24.00');
        $this->assertEqualsWithDelta(710.2, (float) $cn->total_amount, 0.01, 'total recalculated from the edited lines');

        $lines = DB::table('customer_credit_note_lines')->where('credit_note_id', $cnId)->orderBy('id')->get();
        $this->assertCount(2, $lines, 'lines are replaced, not appended');
        $this->assertEqualsWithDelta(3.0, (float) $lines[0]->quantity, 0.001);
        $this->assertEqualsWithDelta(6.0, (float) $lines[0]->tax_rate, 0.001);
        $this->assertEqualsWithDelta(286.2, (float) $lines[0]->line_total, 0.01);
        $this->assertEqualsWithDelta(6.0, (float) $lines[1]->tax_rate, 0.001, 'CN5: rate inherited from the source invoice line');
        $this->assertEqualsWithDelta(424.0, (float) $lines[1]->line_total, 0.01);

        // The response carries the recalculated document.
        $this->assertEqualsWithDelta(710.2, (float) $response->json('total_amount'), 0.01);
        $this->assertCount(2, $response->json('lines'));

        // The edited draft posts and the books balance at the new total.
        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$cnId}/post")
            ->assertOk();

        $entryId = (int) DB::table('customer_credit_notes')->where('id', $cnId)->value('journal_entry_id');
        $sums = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $entryId)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();
        $this->assertEqualsWithDelta(710.2, (float) $sums->d, 0.01);
        $this->assertEqualsWithDelta((float) $sums->d, (float) $sums->c, 0.01, 'JE must balance');
    }

    public function test_edited_line_cannot_re_rate_the_source_invoice_line(): void
    {
        $invoice = $this->invoice();
        $widget = $invoice->lines->firstWhere('description', 'Widget');
        $cnId = $this->draftFor($invoice);

        $this->actingAs($this->user)->putJson("/api/v1/accounting/customers/credit-notes/{$cnId}", [
            'lines' => [
                ['invoice_line_id' => $widget->id, 'description' => 'Widget', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ])->assertStatus(422);

        // Nothing changed: the rejected edit is rolled back as a whole.
        $lines = DB::table('customer_credit_note_lines')->where('credit_note_id', $cnId)->get();
        $this->assertCount(1, $lines);
        $this->assertEqualsWithDelta(1.0, (float) $lines[0]->quantity, 0.001);
        $this->assertEqualsWithDelta(106.0, (float) DB::table('customer_credit_notes')->where('id', $cnId)->value('total_amount'), 0.01);
    }

    public function test_edited_quantity_beyond_invoiced_is_rejected_at_post(): void
    {
        $invoice = $this->invoice();
        $widget = $invoice->lines->firstWhere('description', 'Widget');
        $cnId = $this->draftFor($invoice);

        $this->actingAs($this->user)->putJson("/api/v1/accounting/customers/credit-notes/{$cnId}", [
            'lines' => [
                ['invoice_line_id' => $widget->id, 'description' => 'Widget', 'quantity' => 11, 'unit_price' => 100],
            ],
        ])->assertOk();

        // CN4: the credit cap is enforced on the edited lines.
        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$cnId}/post")
            ->assertStatus(422);
        $this->assertSame('draft', DB::table('customer_credit_notes')->where('id', $cnId)->value('status'));
    }

    public function test_posted_credit_note_rejects_line_edits(): void
    {
        $invoice = $this->invoice();
        $widget = $invoice->lines->firstWhere('description', 'Widget');
        $cnId = $this->draftFor($invoice);

        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$cnId}/post")
            ->assertOk();

        $this->actingAs($this->user)->putJson("/api/v1/accounting/customers/credit-notes/{$cnId}", [
            'lines' => [
                ['invoice_line_id' => $widget->id, 'description' => 'Widget', 'quantity' => 5, 'unit_price' => 100],
            ],
        ])->assertStatus(403);

        $lines = DB::table('customer_credit_note_lines')->where('credit_note_id', $cnId)->get();
        $this->assertCount(1, $lines);
        $this->assertEqualsWithDelta(1.0, (float) $lines[0]->quantity, 0.001);
        $this->assertEqualsWithDelta(106.0, (float) DB::table('customer_credit_notes')->where('id', $cnId)->value('total_amount'), 0.01);
    }
}

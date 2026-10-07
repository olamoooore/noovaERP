<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #527 (SALES-ODOO) — credit note reversal wizard.
 *
 * Odoo's "Credit Note" on an invoice offers three modes:
 *  - partial refund: a DRAFT credit note to edit (the #522 dialog — the
 *    invoice balance moves only when that draft is posted);
 *  - full refund: the credit note is POSTED and reconciled with the invoice;
 *  - full refund + new draft invoice: as full refund, plus a new DRAFT
 *    invoice copied from the original and linked to the credit note.
 *
 * Every test drives the real routes and asserts the business rules:
 *  - invoice balance = total − paid − credited after each option;
 *  - the re-issue option leaves the original fully credited and a new draft
 *    invoice with the same lines, linked to the credit note;
 *  - the journal balances (debits = credits) and the AR subledger (sum of
 *    open invoice balances) equals the AR ledger.
 */
class Issue527CreditNoteReversalWizardTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private string $tenantId;

    private int $arAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->assertTenantSchemaReady([
            'invoices',
            'invoice_lines',
            'customers',
            'customer_credit_notes',
            'customer_credit_note_lines',
            'chart_of_accounts',
            'account_mappings',
            'journal_entries',
            'journal_entry_lines',
        ]);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = $this->makeUser('admin', UserRole::ADMIN);
        $this->customer = Customer::factory()->create();

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-527', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-527', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-527', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);
        $this->arAccountId = (int) $ar->id;

        AccountMapping::whereIn('mapping_key', ['sales_revenue', 'accounts_receivable', 'sales_tax_payable'])->delete();
        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * A real invoice: created and posted through the invoice routes, so its
     * AR debit sits in the ledger. Two lines — 3 × 100 and 2 × 250 with a 50
     * line discount — total 750.
     */
    private function postedInvoice(): Invoice
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [
                ['description' => 'Widget', 'quantity' => 3, 'unit_price' => 100, 'tax_rate' => 0, 'discount_amount' => 0],
                ['description' => 'Service', 'quantity' => 2, 'unit_price' => 250, 'tax_rate' => 0, 'discount_amount' => 50],
            ],
        ]);
        $create->assertCreated($create->getContent());
        $invoiceId = (int) $create->json('id');

        $post = $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/post");
        $post->assertOk($post->getContent());

        $invoice = Invoice::with('lines')->findOrFail($invoiceId);
        $this->assertEqualsWithDelta(750.0, (float) $invoice->total, 0.001, 'fixture: invoice total 750');
        $this->assertNotNull($invoice->journal_entry_id, 'fixture: invoice must be posted to the ledger');

        return $invoice;
    }

    private function reverse(Invoice $invoice, string $mode, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson(
            "/api/v1/accounting/customers/invoices/{$invoice->id}/credit-note-reversal",
            [
                'mode' => $mode,
                'credit_note_date' => now()->toDateString(),
                'reason' => 'Wrong price',
            ],
        );
    }

    private function assertBalanceRule(Invoice $invoice, string $context): void
    {
        $invoice->refresh();
        $expected = max(0.0, round((float) $invoice->total - (float) $invoice->paid_amount - (float) $invoice->credited_amount, 2));
        $this->assertEqualsWithDelta($expected, (float) $invoice->balance, 0.001, "{$context}: balance = total − paid − credited");
    }

    /** Debits = credits for every journal entry, and AR ledger == AR subledger. */
    private function assertBooksBalance(string $context): void
    {
        $unbalanced = DB::table('journal_entry_lines')
            ->select('journal_entry_id')
            ->groupBy('journal_entry_id')
            ->havingRaw('ABS(COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0)) > 0.005')
            ->pluck('journal_entry_id');
        $this->assertCount(0, $unbalanced, "{$context}: every journal entry must balance (debits = credits)");

        $arLedger = (float) DB::table('journal_entry_lines')
            ->where('account_id', $this->arAccountId)
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as net')
            ->value('net');

        $arSubledger = (float) Invoice::where('customer_id', $this->customer->id)
            ->where('status', '!=', 'draft')
            ->sum('balance');

        $this->assertEqualsWithDelta($arSubledger, $arLedger, 0.005, "{$context}: AR ledger must equal the AR subledger");
    }

    public function test_partial_refund_is_a_draft_credit_note_and_moves_the_balance_only_when_posted(): void
    {
        $invoice = $this->postedInvoice();
        $widget = $invoice->lines->firstWhere('description', 'Widget');

        // #522 dialog path: a draft credit note for 1 of the 3 widgets.
        $draft = $this->actingAs($this->admin)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'One widget returned',
            'lines' => [[
                'invoice_line_id' => $widget->id,
                'description' => 'Widget',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ]);
        $draft->assertCreated();
        $creditNoteId = (int) $draft->json('id');

        $this->assertSame('draft', CustomerCreditNote::findOrFail($creditNoteId)->status);
        $invoice->refresh();
        $this->assertEqualsWithDelta(750.0, (float) $invoice->balance, 0.001, 'a draft credit note does not move the balance');
        $this->assertBalanceRule($invoice, 'partial (draft)');
        $this->assertBooksBalance('partial (draft)');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/post")
            ->assertOk();

        $invoice->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $invoice->credited_amount, 0.001);
        $this->assertEqualsWithDelta(650.0, (float) $invoice->balance, 0.001, 'partial: 750 − 0 − 100');
        $this->assertBalanceRule($invoice, 'partial (posted)');
        $this->assertBooksBalance('partial (posted)');
    }

    public function test_full_refund_posts_a_credit_note_reconciled_with_the_invoice(): void
    {
        $invoice = $this->postedInvoice();

        $response = $this->reverse($invoice, 'full_refund');
        $response->assertCreated($response->getContent());

        $creditNote = CustomerCreditNote::with('lines')->findOrFail((int) $response->json('credit_note.id'));
        $this->assertSame('posted', $creditNote->status, 'full refund posts the credit note');
        $this->assertNotNull($creditNote->journal_entry_id, 'posted through the Posting Engine');
        $this->assertSame((int) $invoice->id, (int) $creditNote->invoice_id);
        $this->assertSame('Wrong price', $creditNote->reason);
        $this->assertEqualsWithDelta(750.0, (float) $creditNote->total_amount, 0.001, 'full refund credits the whole invoice');
        $this->assertCount(2, $creditNote->lines);
        $this->assertNull($response->json('invoice'), 'full refund creates no new invoice');

        $invoice->refresh();
        $this->assertEqualsWithDelta(750.0, (float) $invoice->credited_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->balance, 0.001, 'full refund: 750 − 0 − 750');
        $this->assertBalanceRule($invoice, 'full refund');
        $this->assertBooksBalance('full refund');
    }

    public function test_full_refund_credits_only_what_is_left_after_an_earlier_credit_note(): void
    {
        $invoice = $this->postedInvoice();
        $widget = $invoice->lines->firstWhere('description', 'Widget');

        $earlier = $this->actingAs($this->admin)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'lines' => [['invoice_line_id' => $widget->id, 'description' => 'Widget', 'quantity' => 1, 'unit_price' => 100]],
        ]);
        $earlier->assertCreated();
        $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/customers/credit-notes/'.$earlier->json('id').'/post')
            ->assertOk();

        $response = $this->reverse($invoice, 'full_refund');
        $response->assertCreated($response->getContent());

        $creditNote = CustomerCreditNote::with('lines')->findOrFail((int) $response->json('credit_note.id'));
        $this->assertEqualsWithDelta(650.0, (float) $creditNote->total_amount, 0.001, 'only the remaining 650 is credited');
        $this->assertEqualsWithDelta(2.0, (float) $creditNote->lines->firstWhere('invoice_line_id', $widget->id)->quantity, 0.0001);

        $invoice->refresh();
        $this->assertEqualsWithDelta(750.0, (float) $invoice->credited_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->balance, 0.001);
        $this->assertBalanceRule($invoice, 'full refund after partial');
        $this->assertBooksBalance('full refund after partial');

        // Nothing left to credit: a second full refund is refused.
        $this->reverse($invoice, 'full_refund')->assertStatus(422);
    }

    public function test_full_refund_and_new_draft_reissues_the_invoice_linked_to_the_credit_note(): void
    {
        $invoice = $this->postedInvoice();
        $journalCountBefore = DB::table('journal_entries')->count();

        $response = $this->reverse($invoice, 'full_refund_new_draft');
        $response->assertCreated($response->getContent());

        $creditNote = CustomerCreditNote::findOrFail((int) $response->json('credit_note.id'));
        $this->assertSame('posted', $creditNote->status);

        // Original: fully credited.
        $invoice->refresh();
        $this->assertEqualsWithDelta(750.0, (float) $invoice->credited_amount, 0.001, 'original is fully credited');
        $this->assertEqualsWithDelta(0.0, (float) $invoice->balance, 0.001);
        $this->assertBalanceRule($invoice, 'original after re-issue');

        // New draft: same customer, same lines, linked to the credit note and the original.
        $newId = (int) $response->json('invoice.id');
        $this->assertGreaterThan(0, $newId, 'a new draft invoice is returned');
        $new = Invoice::with('lines')->findOrFail($newId);

        $this->assertNotSame((int) $invoice->id, (int) $new->id);
        $this->assertSame('draft', $new->status, 'the re-issued invoice is a draft to correct');
        $this->assertNull($new->journal_entry_id, 'a draft never touches the ledger');
        $this->assertSame((int) $creditNote->id, (int) $new->credit_note_id, 'new draft is linked to the credit note');
        $this->assertSame((int) $invoice->id, (int) $new->original_document_id, 'new draft is linked to the original invoice');
        $this->assertSame((int) $invoice->customer_id, (int) $new->customer_id);
        $this->assertSame($invoice->currency_code, $new->currency_code);
        $this->assertEqualsWithDelta(750.0, (float) $new->total, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $new->paid_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $new->credited_amount, 0.001);

        $shape = fn ($lines) => $lines->sortBy('description')->map(fn ($l) => [
            'product_id' => $l->product_id,
            'description' => $l->description,
            'quantity' => round((float) $l->quantity, 4),
            'unit_price' => round((float) $l->unit_price, 2),
            'tax_rate' => round((float) $l->tax_rate, 2),
            'tax_inclusive' => (bool) $l->tax_inclusive,
            'discount_amount' => round((float) $l->discount_amount, 2),
            'line_total' => round((float) $l->line_total, 2),
        ])->values()->all();
        $this->assertSame($shape($invoice->lines()->get()), $shape($new->lines), 'the new draft carries the same lines');

        // Only the credit note hit the ledger; the books balance.
        $this->assertSame($journalCountBefore + 1, DB::table('journal_entries')->count(), 'only the credit note posts a journal entry');
        $this->assertBooksBalance('full refund + new draft');
    }

    public function test_reversal_is_refused_on_a_draft_invoice(): void
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [['description' => 'Widget', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0, 'discount_amount' => 0]],
        ]);
        $create->assertCreated();
        $draft = Invoice::findOrFail((int) $create->json('id'));

        $this->reverse($draft, 'full_refund')->assertStatus(422);
        $this->assertSame(0, CustomerCreditNote::where('invoice_id', $draft->id)->count());
    }

    public function test_reversal_requires_accounting_post(): void
    {
        $invoice = $this->postedInvoice();
        $manager = $this->makeUser('manager', UserRole::MANAGER);

        $this->reverse($invoice, 'full_refund', $manager)->assertForbidden();
        $this->assertSame(0, CustomerCreditNote::where('invoice_id', $invoice->id)->count());
        $this->assertEqualsWithDelta(750.0, (float) $invoice->fresh()->balance, 0.001);
    }
}

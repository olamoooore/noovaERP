<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #510 (CN1) — posting a customer credit note INCREASED the invoice
 * balance: postCreditNote called InvoiceService::applyRefundToInvoice, a cash
 * refund helper that decrements paid_amount, so an unpaid 1,000 invoice with a
 * 200 credit note ended up with paid −200 / balance 1,200, and a paid invoice
 * flipped to partial 200 — AR subledger and GL diverged by twice the credit.
 *
 * Drives the real routes (store → post → void) and asserts the decided
 * contract: credited_amount tracked separately, balance = max(0, total − paid
 * − credited), excess becomes customer credit, void restores the original
 * state.
 */
class CreditNoteInvoiceBalanceTest extends TenantTestCase
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

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-CN1', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-CN1', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-CN1', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    private function invoice(float $total, float $paid, float $balance, string $status): Invoice
    {
        return Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => $status,
            'lifecycle_status' => 'posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => $paid,
            'balance' => $balance,
            'created_by' => $this->user->id,
        ]);
    }

    /** Create + post a credit note of $amount against $invoice through the router. */
    private function postCreditNote(Invoice $invoice, float $amount): int
    {
        $created = $this->actingAs($this->user)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'CN1 balance fix',
            'lines' => [
                [
                    'description' => 'Credit against '.$invoice->invoice_number,
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'tax_rate' => 0,
                ],
            ],
        ]);
        $created->assertCreated();
        $id = (int) $created->json('id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$id}/post")
            ->assertOk();

        return $id;
    }

    public function test_credit_note_lowers_balance_on_unpaid_invoice(): void
    {
        $invoice = $this->invoice(1000.00, 0.00, 1000.00, 'sent');

        $this->postCreditNote($invoice, 200.00);

        $invoice->refresh();
        $this->customer->refresh();

        $this->assertSame(0.0, round((float) $invoice->paid_amount, 2), 'unpaid invoice: paid_amount must stay 0');
        $this->assertSame(800.0, round((float) $invoice->balance, 2), 'balance = total − paid − credited = 1000 − 0 − 200');
        $this->assertSame('sent', $invoice->status);
        $this->assertSame(0.0, round((float) $this->customer->fresh()->credit_balance, 2), 'no excess credit on an open invoice');
        $this->assertSame(800.0, round((float) $this->customer->fresh()->total_outstanding, 2), 'customer exposure follows the corrected balance');

        // Books still balance: the credit note's own journal entry is balanced.
        $entry = DB::table('journal_entries')
            ->where('id', (int) DB::table('customer_credit_notes')
                ->where('invoice_id', $invoice->id)
                ->value('journal_entry_id'))
            ->first();
        $this->assertNotNull($entry, 'credit note must post a journal entry');
        $sums = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $entry->id)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();
        $this->assertEqualsWithDelta((float) $sums->d, (float) $sums->c, 0.01, 'credit note JE must balance');
    }

    public function test_credit_note_on_paid_invoice_becomes_customer_credit(): void
    {
        $invoice = $this->invoice(1000.00, 1000.00, 0.00, 'paid');

        $this->postCreditNote($invoice, 200.00);

        $invoice->refresh();

        $this->assertSame(1000.0, round((float) $invoice->paid_amount, 2), 'cash received must not be clawed back by a credit note');
        $this->assertSame(0.0, round((float) $invoice->balance, 2), 'balance floored at 0, never negative');
        $this->assertSame('paid', $invoice->status, 'settled invoice stays paid, not partial');
        $this->assertSame(200.0, round((float) $this->customer->fresh()->credit_balance, 2), 'excess credit of 200 held for the customer');
        $this->assertSame(0.0, round((float) $this->customer->fresh()->total_outstanding, 2), 'no phantom outstanding');
    }

    public function test_void_restores_original_state(): void
    {
        $open = $this->invoice(1000.00, 0.00, 1000.00, 'sent');
        $paid = $this->invoice(1000.00, 1000.00, 0.00, 'paid');
        // distinct invoice numbers come from the factory; fetch for clarity
        $paid = Invoice::find($paid->id);

        $openCn = $this->postCreditNote($open, 200.00);
        $paidCn = $this->postCreditNote($paid, 200.00);

        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$openCn}/void")
            ->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$paidCn}/void")
            ->assertOk();

        $open->refresh();
        $paid->refresh();
        $this->customer->refresh();

        $this->assertSame(0.0, round((float) $open->paid_amount, 2));
        $this->assertSame(1000.0, round((float) $open->balance, 2), 'void restores the open balance');
        $this->assertSame(1000.0, round((float) $paid->paid_amount, 2));
        $this->assertSame(0.0, round((float) $paid->balance, 2));
        $this->assertSame(0.0, round((float) $this->customer->fresh()->credit_balance, 2), 'void releases the customer credit');
        $this->assertSame(1000.0, round((float) $this->customer->fresh()->total_outstanding, 2));
    }
}

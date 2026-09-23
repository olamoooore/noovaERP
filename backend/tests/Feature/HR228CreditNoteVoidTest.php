<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerCreditNoteService;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #228 (regression from the #169 fix) — a posted credit note permanently
 * blocked invoice cancel/reset-to-draft, and no application code path could
 * ever move the note out of 'posted'. This verifies the real void endpoint
 * end-to-end: GL reversal through the Posting Engine, invoice balance
 * restoration, and the #169 guard actually clearing.
 */
class HR228CreditNoteVoidTest extends TenantTestCase
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

        $this->assertTenantSchemaReady(['invoices', 'customer_credit_notes', 'chart_of_accounts', 'account_mappings', 'journal_entries']);

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

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-HR228', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-HR228', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-HR228', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    private function postedInvoice(): Invoice
    {
        return Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 1000,
            'paid_amount' => 0,
            'balance' => 1000,
            'created_by' => $this->user->id,
        ]);
    }

    private function draftCreditNote(Invoice $invoice, float $amount = 300): CustomerCreditNote
    {
        return CustomerCreditNote::create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'reason' => 'HR228 void test',
        ]);
    }

    public function test_void_reverses_gl_restores_invoice_and_unblocks_cancel(): void
    {
        $invoice = $this->postedInvoice();
        $creditNote = $this->draftCreditNote($invoice);

        $posted = app(CustomerCreditNoteService::class)->postCreditNote($creditNote);
        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->journal_entry_id, 'Posting must create a journal entry');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/customers/credit-notes/'.$posted->id.'/void');

        $response->assertOk();
        $this->assertSame('voided', $response->json('credit_note.status'));

        $fresh = CustomerCreditNote::find($posted->id);
        $this->assertSame('voided', $fresh->status);
        $this->assertNotNull($fresh->voided_at);
        $this->assertNotNull($fresh->reversal_journal_entry_id, 'Void must record the reversing entry');

        // The reversal is linked to the original posting in the ledger.
        $link = DB::table('journal_entry_links')
            ->where('original_journal_entry_id', $posted->journal_entry_id)
            ->where('relation_type', 'reversal')
            ->first();
        $this->assertNotNull($link, 'A reversal link must exist');
        $this->assertSame((int) $fresh->reversal_journal_entry_id, (int) $link->journal_entry_id);

        // Invoice balance reduced at posting time is restored.
        $this->assertSame(0.0, round((float) $invoice->fresh()->paid_amount, 2));
        $this->assertSame(1000.0, round((float) $invoice->fresh()->balance, 2));

        // The #169 guard's escape hatch works through the real application.
        $cancel = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices/'.$invoice->id.'/cancel');
        $cancel->assertOk();
        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    public function test_only_posted_credit_notes_can_be_voided(): void
    {
        $invoice = $this->postedInvoice();
        $draft = $this->draftCreditNote($invoice);

        $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/customers/credit-notes/'.$draft->id.'/void')
            ->assertStatus(403);

        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_voided_notes_no_longer_count_toward_the_invoice_credit_limit(): void
    {
        $invoice = $this->postedInvoice();

        $first = app(CustomerCreditNoteService::class)->postCreditNote($this->draftCreditNote($invoice, 300));

        $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/customers/credit-notes/'.$first->id.'/void')
            ->assertOk();

        // 800 alone would exceed the remaining 700 if the voided 300 still
        // counted; posting it must now succeed.
        $second = app(CustomerCreditNoteService::class)->postCreditNote($this->draftCreditNote($invoice, 800));
        $this->assertSame('posted', $second->status);
    }
}

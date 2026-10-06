<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\AuditLog;
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
 * Issue #520 (CN11) — the credit note detail page gains a Void action that
 * asks the user for a reason. The reason the user types must reach the books:
 * it is recorded as the reversal reason on the Posting Engine reversal and in
 * the void audit entry, instead of being dropped by the endpoint.
 */
class Issue520CreditNoteVoidReasonTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '52052052-0520-4520-8520-520520520520',
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

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-I520', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-I520', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-I520', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

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
            'reason' => 'I520 void test',
        ]);
    }

    public function test_void_records_the_users_reason_on_the_reversal_and_audit_trail(): void
    {
        $posted = app(CustomerCreditNoteService::class)->postCreditNote($this->draftCreditNote($this->postedInvoice()));

        $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/customers/credit-notes/'.$posted->id.'/void', [
                'reason' => 'Customer returned the goods; credit issued twice',
            ])
            ->assertOk();

        $fresh = CustomerCreditNote::find($posted->id);
        $this->assertSame('voided', $fresh->status);

        $relationship = DB::table('reversal_relationships')
            ->where('original_journal_entry_id', $posted->journal_entry_id)
            ->first();
        $this->assertNotNull($relationship, 'Void must reverse through the Posting Engine');
        $this->assertStringContainsString(
            'Customer returned the goods; credit issued twice',
            (string) $relationship->reversal_reason_text,
        );

        $audit = AuditLog::where('operation', 'customer_credit_note_voided')
            ->where('reference_id', $posted->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame(
            'Customer returned the goods; credit issued twice',
            data_get($audit->metadata, 'reason'),
        );
    }

    public function test_void_rejects_an_over_long_reason(): void
    {
        $posted = app(CustomerCreditNoteService::class)->postCreditNote($this->draftCreditNote($this->postedInvoice()));

        $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/customers/credit-notes/'.$posted->id.'/void', [
                'reason' => str_repeat('x', 501),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame('posted', $posted->fresh()->status);
    }
}

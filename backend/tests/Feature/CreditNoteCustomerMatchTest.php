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
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #511 (CN2) — store() validated customer_id and invoice_id
 * independently, so a credit note for customer A could be posted against
 * customer B's invoice: the wrong statement credited, the wrong invoice moved
 * (see CN1), and the e-invoice named the wrong buyer.
 *
 * Contract: customer_id must match the invoice's customer — rejected with 422
 * at store time AND re-enforced at post time.
 */
class CreditNoteCustomerMatchTest extends TenantTestCase
{
    private User $user;

    private Customer $customerA;

    private Customer $customerB;

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

        $this->customerA = Customer::factory()->create(['name' => 'CN2 Customer A']);
        $this->customerB = Customer::factory()->create(['name' => 'CN2 Customer B']);

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-CN2', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-CN2', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-CN2', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    private function invoiceFor(Customer $customer): Invoice
    {
        return Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'sent',
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

    private function payload(int $customerId, int $invoiceId): array
    {
        return [
            'customer_id' => $customerId,
            'invoice_id' => $invoiceId,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'CN2 customer match',
            'lines' => [
                ['description' => 'Credit', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ];
    }

    public function test_store_rejects_credit_note_for_a_different_customer_than_the_invoice(): void
    {
        $invoiceB = $this->invoiceFor($this->customerB);

        $before = CustomerCreditNote::count();

        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/customers/credit-notes',
            $this->payload((int) $this->customerA->id, (int) $invoiceB->id),
        );

        $response->assertStatus(422);
        $this->assertSame($before, CustomerCreditNote::count(), 'rejected request must not persist a credit note');
    }

    public function test_store_accepts_matching_customer_and_invoice(): void
    {
        $invoiceB = $this->invoiceFor($this->customerB);

        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/customers/credit-notes',
            $this->payload((int) $this->customerB->id, (int) $invoiceB->id),
        );

        $response->assertCreated();
        $this->assertSame(
            (int) $invoiceB->customer_id,
            (int) CustomerCreditNote::find($response->json('id'))->customer_id,
        );
    }

    public function test_post_rejects_credit_note_drifted_from_its_invoice_customer(): void
    {
        $invoiceB = $this->invoiceFor($this->customerB);

        // Simulate legacy/direct data: customer A paired with B's invoice.
        $drifted = CustomerCreditNote::create([
            'customer_id' => $this->customerA->id,
            'invoice_id' => $invoiceB->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'reason' => 'CN2 drifted pair',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/customers/credit-notes/'.$drifted->id.'/post');

        $response->assertStatus(422);
        $this->assertSame('draft', $drifted->fresh()->status, 'a drifted credit note must never reach the ledger');
    }
}

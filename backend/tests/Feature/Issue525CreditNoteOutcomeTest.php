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
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #525 (CN-UX4) — the credit note list and detail must show what
 * happened to the credit: how much reduced the credited invoice (applied to),
 * how much is left over as customer credit (remaining), and the e-invoice
 * status — and the list must be filterable by them.
 *
 * The outcome is derived from existing data: a posted credit note is applied
 * to its invoice's open balance (total − paid − written off); credit the
 * invoice cannot absorb is parked as customer credit (#510). Several credit
 * notes on one invoice consume the open balance in posting order.
 */
class Issue525CreditNoteOutcomeTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->assertTenantSchemaReady([
            'invoices',
            'customers',
            'customer_credit_notes',
            'customer_credit_note_lines',
            'acct_compliance_documents',
            'acct_compliance_submissions',
        ]);

        $this->user = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-525', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-525', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-525', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }

    private function invoice(float $total, float $paid, string $status): Invoice
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
            'balance' => $total - $paid,
            'created_by' => $this->user->id,
        ]);
    }

    private function draftCreditNote(Invoice $invoice, float $amount): int
    {
        $created = $this->actingAs($this->user)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => '#525 outcome',
            'lines' => [[
                'description' => 'Credit against '.$invoice->invoice_number,
                'quantity' => 1,
                'unit_price' => $amount,
                'tax_rate' => 0,
            ]],
        ]);
        $created->assertCreated();

        return (int) $created->json('id');
    }

    private function postedCreditNote(Invoice $invoice, float $amount): int
    {
        $id = $this->draftCreditNote($invoice, $amount);

        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$id}/post")
            ->assertOk();

        return $id;
    }

    private function complianceDocument(int $creditNoteId, string $status, ?string $submissionStatus = null): void
    {
        $documentId = DB::table('acct_compliance_documents')->insertGetId([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => 'credit_note',
            'document_id' => $creditNoteId,
            'document_number' => 'CN-'.$creditNoteId,
            'issued_date' => now()->toDateString(),
            'total_amount' => 0,
            'currency_code' => 'MYR',
            'compliance_status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($submissionStatus !== null) {
            DB::table('acct_compliance_submissions')->insert([
                'tenant_id' => $this->tenantId,
                'document_id' => $documentId,
                'country_code' => 'MY',
                'submission_status' => $submissionStatus,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> credit note id => list row */
    private function listRows(array $query = []): array
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/customers/credit-notes?'.http_build_query($query));
        $response->assertOk();

        $rows = [];
        foreach ($response->json('data') as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    public function test_list_and_detail_show_applied_amount_remaining_credit_and_invoice(): void
    {
        $open = $this->invoice(1000.00, 0.00, 'sent');
        $paid = $this->invoice(1000.00, 1000.00, 'paid');
        $partly = $this->invoice(1000.00, 900.00, 'partial');

        $applied = $this->postedCreditNote($open, 200.00);
        $credit = $this->postedCreditNote($paid, 200.00);
        // Open balance 100: the first note absorbs it and parks 50 as credit,
        // the second one finds nothing left to apply to.
        $split = $this->postedCreditNote($partly, 150.00);
        $later = $this->postedCreditNote($partly, 30.00);
        $draft = $this->draftCreditNote($open, 10.00);

        $rows = $this->listRows();

        $this->assertSame(200.0, (float) $rows[$applied]['outcome']['applied_amount'], 'open invoice absorbs the whole credit');
        $this->assertSame(0.0, (float) $rows[$applied]['outcome']['remaining_credit']);
        $this->assertSame('applied', $rows[$applied]['outcome']['state']);
        $this->assertSame($open->id, (int) $rows[$applied]['outcome']['applied_to']['invoice_id']);
        $this->assertSame($open->invoice_number, $rows[$applied]['outcome']['applied_to']['invoice_number']);

        $this->assertSame(0.0, (float) $rows[$credit]['outcome']['applied_amount'], 'a settled invoice cannot absorb credit');
        $this->assertSame(200.0, (float) $rows[$credit]['outcome']['remaining_credit'], 'credit = total − applied − refunded');
        $this->assertSame('customer_credit', $rows[$credit]['outcome']['state']);
        $this->assertNull($rows[$credit]['outcome']['applied_to'], 'nothing was applied, so no invoice link');

        $this->assertSame(100.0, (float) $rows[$split]['outcome']['applied_amount']);
        $this->assertSame(50.0, (float) $rows[$split]['outcome']['remaining_credit']);
        $this->assertSame('partially_applied', $rows[$split]['outcome']['state']);

        $this->assertSame(0.0, (float) $rows[$later]['outcome']['applied_amount'], 'later note finds the open balance consumed');
        $this->assertSame(30.0, (float) $rows[$later]['outcome']['remaining_credit']);

        $this->assertNull($rows[$draft]['outcome'], 'a draft has no outcome yet');

        // The remaining credit per note reconciles with the customer credit
        // balance the ledger-side service derives (#510).
        $this->assertEqualsWithDelta(
            (float) $this->customer->fresh()->credit_balance,
            200.0 + 50.0 + 30.0,
            0.01,
        );

        $detail = $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/customers/credit-notes/{$split}")
            ->assertOk();
        $this->assertSame(100.0, (float) $detail->json('outcome.applied_amount'));
        $this->assertSame(50.0, (float) $detail->json('outcome.remaining_credit'));
        $this->assertSame($partly->id, (int) $detail->json('outcome.applied_to.invoice_id'));
        $this->assertSame('not_submitted', $detail->json('einvoice_status'));
    }

    public function test_voided_note_releases_its_credit(): void
    {
        $open = $this->invoice(1000.00, 0.00, 'sent');
        $id = $this->postedCreditNote($open, 200.00);

        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$id}/void", ['reason' => 'wrong customer'])
            ->assertOk();

        $row = $this->listRows()[$id];

        $this->assertSame('voided', $row['outcome']['state']);
        $this->assertSame(0.0, (float) $row['outcome']['applied_amount']);
        $this->assertSame(0.0, (float) $row['outcome']['remaining_credit']);
    }

    public function test_list_shows_einvoice_status_for_every_posted_note(): void
    {
        $open = $this->invoice(5000.00, 0.00, 'sent');

        $bare = $this->postedCreditNote($open, 10.00);
        $submitted = $this->postedCreditNote($open, 10.00);
        $valid = $this->postedCreditNote($open, 10.00);
        $rejected = $this->postedCreditNote($open, 10.00);
        $draft = $this->draftCreditNote($open, 10.00);

        $this->complianceDocument($submitted, 'submitted');
        $this->complianceDocument($valid, 'accepted', 'accepted');
        $this->complianceDocument($rejected, 'submitted', 'rejected');

        $rows = $this->listRows();

        $this->assertSame('not_submitted', $rows[$bare]['einvoice_status']);
        $this->assertSame('submitted', $rows[$submitted]['einvoice_status']);
        $this->assertSame('valid', $rows[$valid]['einvoice_status']);
        $this->assertSame('invalid', $rows[$rejected]['einvoice_status'], 'a rejected submission wins over the document state');
        $this->assertNull($rows[$draft]['einvoice_status'], 'drafts are not e-invoiceable');
    }

    public function test_list_is_filterable_by_einvoice_status_and_outcome(): void
    {
        $open = $this->invoice(5000.00, 0.00, 'sent');
        $paid = $this->invoice(1000.00, 1000.00, 'paid');

        $bare = $this->postedCreditNote($open, 10.00);
        $valid = $this->postedCreditNote($open, 10.00);
        $credit = $this->postedCreditNote($paid, 40.00);
        $draft = $this->draftCreditNote($open, 10.00);

        $this->complianceDocument($valid, 'accepted');

        $this->assertSame([$valid], array_keys($this->listRows(['einvoice_status' => 'valid'])));

        $notSubmitted = array_keys($this->listRows(['einvoice_status' => 'not_submitted']));
        sort($notSubmitted);
        $this->assertSame([$bare, $credit], $notSubmitted, 'drafts are not "not submitted" e-invoices');

        $this->assertSame([$credit], array_keys($this->listRows(['outcome' => 'customer_credit'])));

        $applied = array_keys($this->listRows(['outcome' => 'applied']));
        sort($applied);
        $this->assertSame([$bare, $valid], $applied);
        $this->assertNotContains($draft, $applied);
    }

    public function test_outcome_does_not_add_queries_per_row(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->listRows();
            // The customer's appended loyalty_points accessor already runs a
            // query per serialized row (pre-existing, outside this issue), so
            // it is excluded from the count.
            $queries = count(array_filter(
                DB::getQueryLog(),
                fn (array $q) => ! str_contains($q['query'], 'loyalty'),
            ));
            DB::disableQueryLog();

            return $queries;
        };

        $open = $this->invoice(5000.00, 0.00, 'sent');
        $this->postedCreditNote($open, 10.00);
        $this->postedCreditNote($this->invoice(500.00, 500.00, 'paid'), 10.00);
        $few = $count();

        foreach (range(1, 4) as $i) {
            $this->postedCreditNote($this->invoice(100.00, 0.00, 'sent'), 10.00);
        }
        $many = $count();

        $this->assertSame($few, $many, 'list outcome must be computed per page, not per row');
    }
}

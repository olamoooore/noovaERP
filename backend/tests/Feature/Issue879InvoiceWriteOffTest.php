<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentAllocationService;
use App\Services\PaymentPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #879 (SA6) — the core, self-contained legs:
 *  - "Write off" action on an open invoice (full or partial): amount, reason,
 *    explicit expense account, PostingEngine posting, `written_off` status with
 *    balance zero on a full write-off;
 *  - recovery of a written-off amount through a payment allocation.
 *
 * The statutory legs (GST bad-debt relief per #745, ECL allowance) are not
 * marked verified in the repo's tax-pack data (config/tax_packs.php,
 * `verified => false`) and are deliberately not implemented here.
 *
 * Everything goes through the real HTTP routes (router → middleware →
 * controller) and the real PostingEngine — never mocked.
 */
class Issue879InvoiceWriteOffTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private ChartOfAccount $receivable;

    private ChartOfAccount $badDebt;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->admin = User::factory()->create(['email' => 'write-off-'.uniqid().'@example.local']);
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        $this->customer = Customer::factory()->create(['payment_terms' => 30]);
        $this->receivable = ChartOfAccount::factory()->create([
            'code' => 'WO-1240', 'name' => 'WO Accounts Receivable', 'type' => 'asset', 'is_active' => true,
        ]);
        $revenue = ChartOfAccount::factory()->create([
            'code' => 'WO-4100', 'name' => 'WO Sales Revenue', 'type' => 'revenue', 'is_active' => true,
        ]);
        $this->badDebt = ChartOfAccount::factory()->create([
            'code' => 'WO-5900', 'name' => 'WO Bad Debt Expense', 'type' => 'expense', 'is_active' => true,
        ]);

        foreach (['accounts_receivable' => $this->receivable->id, 'sales_revenue' => $revenue->id] as $key => $accountId) {
            DB::table('account_mappings')->where('mapping_key', $key)->delete();
            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => $accountId,
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->product = Product::factory()->create([
            'name' => 'WO Consulting Hours', 'sku' => 'WO-SRV-'.Str::upper(Str::random(5)),
            'price' => 100, 'cost' => 0, 'track_inventory' => false,
        ]);
    }

    /** AC bullet 1: full write-off → balanced DR bad-debt / CR AR, status `written_off`, balance zero. */
    public function test_full_write_off_posts_balanced_journal_and_marks_invoice_written_off(): void
    {
        $invoice = $this->createPostedInvoice(100.0);

        $response = $this->writeOff($invoice, reason: 'Customer insolvent — debt confirmed uncollectible');
        $response->assertCreated($response->getContent());

        $invoice->refresh();
        $this->assertSame('written_off', $invoice->status);
        $this->assertSame(0.0, round((float) $invoice->balance, 2));
        $this->assertSame(100.0, round((float) $invoice->written_off_amount, 2));

        $link = JournalEntryLink::where('source_document_type', 'invoice_write_off')->where('relation_type', 'post')->first();
        $this->assertNotNull($link, 'The write-off must create a linked journal entry.');

        $je = JournalEntry::with('lines')->findOrFail($link->journal_entry_id);
        $debit = $je->lines->firstWhere('account_id', $this->badDebt->id);
        $credit = $je->lines->firstWhere('account_id', $this->receivable->id);
        $this->assertSame(100.0, round((float) $debit->debit, 2), 'The write-off must debit the supplied bad-debt expense account.');
        $this->assertSame(100.0, round((float) $credit->credit, 2), 'The write-off must credit the AR control account.');
        $this->assertDebitsEqualCredits($je);

        $writeOff = DB::table('invoice_write_offs')->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($writeOff, 'A write-off record must be kept for audit/recovery.');
        $this->assertSame(100.0, round((float) $writeOff->amount, 2));
        $this->assertSame($this->badDebt->id, (int) $writeOff->account_id);
        $this->assertSame($je->id, (int) $writeOff->journal_entry_id);

        $this->assertTrialBalanceBalanced();
    }

    /** AC bullet 1: a partial write-off reduces the balance without closing the invoice. */
    public function test_partial_write_off_reduces_balance_and_leaves_invoice_open(): void
    {
        $invoice = $this->createPostedInvoice(100.0);

        $this->writeOff($invoice, 40)->assertCreated();

        $invoice->refresh();
        $this->assertNotSame('written_off', $invoice->status);
        $this->assertSame(60.0, round((float) $invoice->balance, 2));
        $this->assertSame(40.0, round((float) $invoice->written_off_amount, 2));

        $je = JournalEntry::with('lines')->findOrFail(
            JournalEntryLink::where('source_document_type', 'invoice_write_off')->value('journal_entry_id')
        );
        $this->assertSame(40.0, round((float) $je->lines->firstWhere('account_id', $this->badDebt->id)->debit, 2));
        $this->assertSame(40.0, round((float) $je->lines->firstWhere('account_id', $this->receivable->id)->credit, 2));
        $this->assertDebitsEqualCredits($je);
    }

    /** A write-off can never exceed the outstanding balance — no negative receivables. */
    public function test_write_off_above_the_outstanding_balance_is_rejected(): void
    {
        $invoice = $this->createPostedInvoice(100.0);

        $this->writeOff($invoice, 150, 'Too much')->assertStatus(422);

        $invoice->refresh();
        $this->assertSame(100.0, round((float) $invoice->balance, 2));
        $this->assertSame(0.0, round((float) $invoice->written_off_amount, 2));
        $this->assertSame(0, DB::table('invoice_write_offs')->count());
        $this->assertSame(0, JournalEntryLink::where('source_document_type', 'invoice_write_off')->count());
    }

    /** AC bullet 2: recovery via allocation reverses the bad-debt expense (DR AR / CR bad-debt) and marks the invoice paid. */
    public function test_recovery_through_payment_allocation_reverses_the_write_off(): void
    {
        $invoice = $this->createPostedInvoice(100.0);

        $this->writeOff($invoice, reason: 'Customer insolvent — debt confirmed uncollectible')->assertCreated();

        $payment = $this->standalonePostedPayment(100.0);

        $this->allocate($payment, $invoice, 100)->assertOk();

        $invoice->refresh();
        $this->assertSame(0.0, round((float) $invoice->written_off_amount, 2), 'The recovery must release the written-off amount.');
        $this->assertSame(100.0, round((float) $invoice->paid_amount, 2));
        $this->assertSame(0.0, round((float) $invoice->balance, 2));
        $this->assertSame('paid', $invoice->status);

        $recoveryLink = JournalEntryLink::where('source_document_type', 'write_off_recovery')->where('relation_type', 'post')->first();
        $this->assertNotNull($recoveryLink, 'The recovery must post its own journal entry.');

        $recovery = JournalEntry::with('lines')->findOrFail($recoveryLink->journal_entry_id);
        $this->assertSame(100.0, round((float) $recovery->lines->firstWhere('account_id', $this->receivable->id)->debit, 2));
        $this->assertSame(100.0, round((float) $recovery->lines->firstWhere('account_id', $this->badDebt->id)->credit, 2));
        $this->assertDebitsEqualCredits($recovery);

        // Bad-debt expense and AR both net to zero over the full story
        // (invoice/write-off/recovery/payment), proving the release is exact.
        $this->assertSame(0.0, $this->accountNetDebit($this->badDebt->id));
        $this->assertSame(0.0, $this->accountNetDebit($this->receivable->id));
        $this->assertTrialBalanceBalanced();
    }

    /** A partial recovery releases only the recovered portion; the rest stays written off. */
    public function test_partial_recovery_releases_only_the_allocated_amount(): void
    {
        $invoice = $this->createPostedInvoice(100.0);

        $this->writeOff($invoice)->assertCreated();

        $payment = $this->standalonePostedPayment(40.0);

        $this->allocate($payment, $invoice, 40)->assertOk();

        $invoice->refresh();
        $this->assertSame(60.0, round((float) $invoice->written_off_amount, 2));
        $this->assertSame(40.0, round((float) $invoice->paid_amount, 2));
        $this->assertSame(0.0, round((float) $invoice->balance, 2));
        $this->assertSame('written_off', $invoice->status);
    }

    /** Allocation beyond the recoverable amount (open balance + written off) is rejected. */
    public function test_allocation_above_the_outstanding_written_off_amount_is_rejected(): void
    {
        $invoice = $this->createPostedInvoice(100.0);

        $this->writeOff($invoice)->assertCreated();

        $payment = $this->standalonePostedPayment(150.0);

        $this->allocate($payment, $invoice, 150)->assertStatus(422);

        $this->assertSame(0.0, round((float) $invoice->fresh()->paid_amount, 2));
        $this->assertSame(100.0, round((float) $invoice->fresh()->written_off_amount, 2));
    }

    /** An allocation spanning the written-off part and the open balance recovers exactly the written-off part and settles the rest. */
    public function test_allocation_spanning_written_off_and_open_balance_settles_both(): void
    {
        $invoice = $this->createPostedInvoice(100.0);

        $this->writeOff($invoice, 40)->assertCreated();
        $this->assertSame(60.0, round((float) $invoice->fresh()->balance, 2));

        $payment = $this->standalonePostedPayment(100.0);

        $this->allocate($payment, $invoice, 100)->assertOk();

        $invoice->refresh();
        $this->assertSame(0.0, round((float) $invoice->written_off_amount, 2));
        $this->assertSame(100.0, round((float) $invoice->paid_amount, 2));
        $this->assertSame(0.0, round((float) $invoice->balance, 2));
        $this->assertSame('paid', $invoice->status);

        // The whole 100 settled this invoice — none may remain allocatable.
        $this->assertSame(0.0, app(PaymentAllocationService::class)->unappliedAmount($payment->fresh()));
    }

    private function writeOff(Invoice $invoice, ?float $amount = null, string $reason = 'Deemed uncollectible')
    {
        $payload = ['reason' => $reason, 'account_id' => $this->badDebt->id];
        if ($amount !== null) {
            $payload['amount'] = $amount;
        }

        return $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoice->id}/write-off", $payload);
    }

    private function allocate(Payment $payment, Invoice $invoice, float $amount)
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment->id}/allocations", [
            'applications' => [['invoice_id' => $invoice->id, 'amount' => $amount]],
        ]);
    }

    private function createPostedInvoice(float $total): Invoice
    {
        $created = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'WO Test Line',
                'quantity' => 1,
                'unit_price' => $total,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $created->assertCreated($created->getContent());

        $invoice = Invoice::findOrFail($created->json('id'));

        $posted = $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoice->id}/post");
        $posted->assertOk($posted->getContent());

        return $invoice->fresh();
    }

    private function standalonePostedPayment(float $amount): Payment
    {
        $payment = Payment::create([
            'customer_id' => $this->customer->id,
            'invoice_id' => null,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'payment_status' => 'recorded',
            'currency' => 'MYR',
            'exchange_rate' => 1,
            'base_currency_code' => 'MYR',
            'received_by' => $this->admin->id,
        ]);

        app(PaymentPostingService::class)->post($payment, $this->admin->id);

        return $payment->fresh();
    }

    private function assertDebitsEqualCredits(JournalEntry $je): void
    {
        $debits = round($je->lines->sum(fn ($line) => (float) $line->debit), 2);
        $credits = round($je->lines->sum(fn ($line) => (float) $line->credit), 2);
        $this->assertSame($debits, $credits, "Unbalanced journal entry #{$je->id}: debit {$debits} vs credit {$credits}");
    }

    private function assertTrialBalanceBalanced(): void
    {
        $debits = round((float) DB::table('journal_entry_lines')->sum('debit'), 2);
        $credits = round((float) DB::table('journal_entry_lines')->sum('credit'), 2);
        $this->assertSame($debits, $credits, "Trial balance is out: debit {$debits} vs credit {$credits}");
    }

    private function accountNetDebit(int $accountId): float
    {
        return round((float) DB::table('journal_entry_lines')
            ->where('account_id', $accountId)
            ->sum(DB::raw('debit - credit')), 2);
    }
}

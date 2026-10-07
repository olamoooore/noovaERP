<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #494 — payment difference handling (Odoo "keep open" vs "mark as
 * fully paid"). A short payment either:
 *   - keeps the invoice open with the residual (keep_open), or
 *   - writes the difference off to the account of a tenant-configured
 *     write-off reason, as an extra line of the payment's own journal entry
 *     posted through PaymentPostingService → PostingEngine, leaving the
 *     invoice fully settled (balance 0, status paid);
 * and the write-off is capped by a per-tenant tolerance.
 *
 * Business rules asserted: balance = total − paid − credited − written off,
 * every journal entry balances, AR ledger == AR subledger, trial balance
 * balances. Everything goes through the real HTTP routes and the real
 * PostingEngine — never mocked.
 */
class Issue494PaymentDifferenceWriteOffTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private ChartOfAccount $receivable;

    private ChartOfAccount $cash;

    private ChartOfAccount $bankCharges;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->admin = User::factory()->create(['email' => 'pay-diff-'.uniqid().'@example.local']);
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
            'code' => 'PD-1240', 'name' => 'PD Accounts Receivable', 'type' => 'asset', 'is_active' => true,
        ]);
        $this->cash = ChartOfAccount::factory()->create([
            'code' => 'PD-1010', 'name' => 'PD Cash', 'type' => 'asset', 'is_active' => true,
        ]);
        $revenue = ChartOfAccount::factory()->create([
            'code' => 'PD-4100', 'name' => 'PD Sales Revenue', 'type' => 'revenue', 'is_active' => true,
        ]);
        $this->bankCharges = ChartOfAccount::factory()->create([
            'code' => 'PD-6150', 'name' => 'PD Bank Charges', 'type' => 'expense', 'is_active' => true,
        ]);

        foreach ([
            'accounts_receivable' => $this->receivable->id,
            'sales_revenue' => $revenue->id,
            'cash' => $this->cash->id,
        ] as $key => $accountId) {
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
            'name' => 'PD Consulting Hours', 'sku' => 'PD-SRV-'.Str::upper(Str::random(5)),
            'price' => 100, 'cost' => 0, 'track_inventory' => false,
        ]);
    }

    /** AC: "keep open" — a short payment leaves the residual open, no write-off line. */
    public function test_keep_open_leaves_the_residual_on_the_invoice(): void
    {
        $this->configureWriteOff(tolerance: 20);
        $invoice = $this->createPostedInvoice(1000.0);

        $payment = $this->recordDraft($invoice, 990, ['payment_difference' => 'keep_open']);
        $this->postPayment($payment);

        $invoice->refresh();
        $this->assertSame(990.0, round((float) $invoice->paid_amount, 2));
        $this->assertSame(10.0, round((float) $invoice->balance, 2));
        $this->assertSame('partial', $invoice->status);
        $this->assertBalanceFormula($invoice);

        $je = JournalEntry::with('lines')->findOrFail($payment->fresh()->journal_entry_id);
        $this->assertNull($je->lines->firstWhere('account_id', $this->bankCharges->id), 'Keep open must not post a write-off line.');
        $this->assertDebitsEqualCredits($je);
        $this->assertArSubledgerMatchesLedger();
        $this->assertTrialBalanceBalanced();
    }

    /** AC: "mark as fully paid" — the difference goes to the reason's account as an extra payment line. */
    public function test_write_off_posts_the_difference_to_the_reason_account_and_settles_the_invoice(): void
    {
        $this->configureWriteOff(tolerance: 20);
        $invoice = $this->createPostedInvoice(1000.0);

        $payment = $this->recordDraft($invoice, 990, [
            'payment_difference' => 'write_off',
            'write_off_reason' => 'bank_charges',
            'write_off_label' => 'TT charges deducted by remitting bank',
        ]);

        // A draft never touches the invoice (#477).
        $this->assertSame(1000.0, round((float) $invoice->fresh()->balance, 2));
        $this->assertSame(10.0, round((float) $payment->write_off_amount, 2));
        $this->assertSame($this->bankCharges->id, (int) $payment->write_off_account_id);

        $this->postPayment($payment);

        $invoice->refresh();
        $this->assertSame(0.0, round((float) $invoice->balance, 2), 'The written-off difference must settle the invoice.');
        $this->assertSame('paid', $invoice->status);
        $this->assertBalanceFormula($invoice);

        // One payment entry: DR cash 990, DR bank charges 10, CR AR 1,000.
        $je = JournalEntry::with('lines')->findOrFail($payment->fresh()->journal_entry_id);
        $this->assertSame(990.0, round((float) $je->lines->firstWhere('account_id', $this->cash->id)->debit, 2));
        $writeOffLine = $je->lines->firstWhere('account_id', $this->bankCharges->id);
        $this->assertNotNull($writeOffLine, 'The difference must be posted to the write-off reason account.');
        $this->assertSame(10.0, round((float) $writeOffLine->debit, 2));
        $this->assertSame(1000.0, round((float) $je->lines->firstWhere('account_id', $this->receivable->id)->credit, 2));
        $this->assertDebitsEqualCredits($je);

        $this->assertArSubledgerMatchesLedger();
        $this->assertTrialBalanceBalanced();
    }

    /** AC: tolerance — a difference above the tenant tolerance cannot be written off. */
    public function test_write_off_above_the_tenant_tolerance_is_rejected(): void
    {
        $this->configureWriteOff(tolerance: 5);
        $invoice = $this->createPostedInvoice(1000.0);

        $this->recordDraftResponse($invoice, 990, [
            'payment_difference' => 'write_off',
            'write_off_reason' => 'bank_charges',
        ])->assertStatus(422);

        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(1000.0, round((float) $invoice->fresh()->balance, 2));
    }

    /** Without a configured tolerance the write-off option is not available. */
    public function test_write_off_is_rejected_when_no_tolerance_is_configured(): void
    {
        $this->configureWriteOff(tolerance: null);
        $invoice = $this->createPostedInvoice(1000.0);

        $this->recordDraftResponse($invoice, 999, [
            'payment_difference' => 'write_off',
            'write_off_reason' => 'bank_charges',
        ])->assertStatus(422);

        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
    }

    /** The account comes only from a configured write-off reason. */
    public function test_write_off_with_an_unknown_reason_is_rejected(): void
    {
        $this->configureWriteOff(tolerance: 20);
        $invoice = $this->createPostedInvoice(1000.0);

        $this->recordDraftResponse($invoice, 990, [
            'payment_difference' => 'write_off',
            'write_off_reason' => 'not_a_reason',
        ])->assertStatus(422);

        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
    }

    /** A reversal undoes the write-off leg together with the cash. */
    public function test_reversing_a_written_off_payment_restores_the_full_balance(): void
    {
        $this->configureWriteOff(tolerance: 20);
        $invoice = $this->createPostedInvoice(1000.0);

        $payment = $this->recordDraft($invoice, 990, [
            'payment_difference' => 'write_off',
            'write_off_reason' => 'bank_charges',
        ]);
        $this->postPayment($payment);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/corrections/payments/{$payment->id}/reverse", [
                'reason_code' => 'data_entry_error',
                'reason_text' => 'Reproduced correction',
            ]);
        $response->assertOk($response->getContent());

        $invoice->refresh();
        $this->assertSame(1000.0, round((float) $invoice->balance, 2));
        $this->assertSame(0.0, round((float) $invoice->paid_amount, 2));
        $this->assertBalanceFormula($invoice);

        $reversal = JournalEntry::with('lines')->findOrFail($response->json('reversal_journal_entry.id'));
        $this->assertSame(10.0, round((float) $reversal->lines->firstWhere('account_id', $this->bankCharges->id)->credit, 2));
        $this->assertDebitsEqualCredits($reversal);
        $this->assertSame(0.0, $this->accountNetDebit($this->bankCharges->id));
        $this->assertArSubledgerMatchesLedger();
        $this->assertTrialBalanceBalanced();
    }

    private function configureWriteOff(?float $tolerance): void
    {
        $payload = [
            'payment' => [
                'write_off_reasons' => [[
                    'code' => 'bank_charges',
                    'label' => 'Bank charges',
                    'account_id' => $this->bankCharges->id,
                ]],
            ],
        ];
        if ($tolerance !== null) {
            $payload['payment']['write_off_tolerance'] = $tolerance;
        }

        $response = $this->actingAs($this->admin)->putJson('/api/v1/settings/sales', $payload);
        $response->assertOk($response->getContent());
    }

    private function recordDraftResponse(Invoice $invoice, float $amount, array $extra = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => 'cash',
        ] + $extra);
    }

    private function recordDraft(Invoice $invoice, float $amount, array $extra = []): Payment
    {
        $response = $this->recordDraftResponse($invoice, $amount, $extra);
        $response->assertCreated($response->getContent());

        return Payment::findOrFail($response->json('id'));
    }

    private function postPayment(Payment $payment): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment->id}/post");
        $response->assertOk($response->getContent());
    }

    private function createPostedInvoice(float $total): Invoice
    {
        $created = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'PD Test Line',
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

    private function assertBalanceFormula(Invoice $invoice): void
    {
        $expected = max(0.0, round(
            (float) $invoice->total
            - (float) $invoice->paid_amount
            - (float) $invoice->credited_amount
            - (float) $invoice->written_off_amount,
            2
        ));
        $this->assertSame($expected, round((float) $invoice->balance, 2), 'balance must equal total − paid − credited − written off');
    }

    private function assertArSubledgerMatchesLedger(): void
    {
        $subledger = round((float) Invoice::where('customer_id', $this->customer->id)
            ->whereNotIn('status', ['draft', 'void', 'cancelled'])
            ->sum('balance'), 2);

        $this->assertSame($subledger, $this->accountNetDebit($this->receivable->id), 'AR ledger must equal the AR subledger.');
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

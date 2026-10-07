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
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #528 (SALES-ODOO): a posted credit note's remaining credit can be paid
 * out to the customer ("Refund") as an outbound payment posted through the
 * PostingEngine (Dr Accounts Receivable / Cr Bank), and voiding that refund
 * restores the credit.
 *
 * Everything runs through the real router → middleware → controller; the
 * invoice, receipt, credit note and refund are posted to the GL by their real
 * routes (PostingEngine, never mocked). Business rules asserted:
 *  - a refund cannot exceed the note's remaining credit
 *    (credit not yet applied to an invoice or refunded);
 *  - the note's remaining credit and the customer's credit balance drop by
 *    the refunded amount;
 *  - the refund journal balances: Dr AR = Cr Bank = refund;
 *  - AR subledger == AR ledger after the refund and after its void;
 *  - a refund from another legal entity's context, or into a bank account in
 *    another currency, is refused with no ledger effect;
 *  - voiding the refund restores the remaining credit and customer balance,
 *    and a credit note cannot be voided while a live refund holds its credit.
 */
class Issue528CreditNoteRefundTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private User $admin;

    private User $viewer;

    private Customer $customer;

    private int $arAccountId;

    private int $bankGlAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue528 Credit Note Refund Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue528 Group',
            'code' => 'I528-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['A', 'B'] as $suffix) {
            $this->{'entity'.$suffix} = DB::table('legal_entities')->insertGetId([
                'group_id' => $groupId,
                'name' => "Entity {$suffix}",
                'code' => "528-{$suffix}-".Str::upper(Str::random(5)),
                'country_code' => 'MY',
                'base_currency' => 'MYR',
                'fiscal_year_start' => '01-01',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->admin = $this->makeUser('admin', 'Admin');
        $this->viewer = $this->makeUser('viewer', UserRole::VIEWER->spatieName());

        $suffix = Str::upper(Str::random(6));
        $ar = ChartOfAccount::factory()->create(['code' => "1100-{$suffix}", 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => "4100-{$suffix}", 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $bank = ChartOfAccount::factory()->create(['code' => "1020-{$suffix}", 'name' => 'Bank', 'type' => 'asset', 'is_active' => true]);
        $this->arAccountId = $ar->id;
        $this->bankGlAccountId = $bank->id;

        foreach (['accounts_receivable' => $ar, 'sales_revenue' => $revenue, 'revenue' => $revenue, 'bank' => $bank, 'cash' => $bank] as $key => $account) {
            AccountMapping::where('mapping_key', $key)->delete();
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id]);
        }

        $code = now()->format('Y-m');
        if (! DB::table('fiscal_periods')->where('code', $code)->exists()) {
            DB::table('fiscal_periods')->insert([
                'name' => now()->format('F Y'),
                'code' => $code,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->customer = Customer::factory()->create(['is_active' => true]);
    }

    private function makeUser(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole);

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $centralRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$this->entityA, $this->entityB] as $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => $centralRole,
                'is_default' => $entityId === $this->entityA,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function as(User $user, ?int $entityId = null): static
    {
        return $this->actingAs($user)->withHeaders(['X-Entity-Id' => (string) ($entityId ?? $this->entityA)]);
    }

    private function postedInvoice(float $total, ?int $entityId = null): Invoice
    {
        $entityId ??= $this->entityA;

        $created = $this->as($this->admin, $entityId)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'description' => 'Issue528 Widget',
                'quantity' => 1,
                'unit_price' => $total,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $created->assertCreated($created->getContent());

        $post = $this->as($this->admin, $entityId)->postJson("/api/v1/invoices/{$created->json('id')}/post");
        $post->assertOk($post->getContent());

        return Invoice::findOrFail($created->json('id'));
    }

    private function receipt(float $amount, array $applications = [], ?int $entityId = null): void
    {
        $this->as($this->admin, $entityId)->postJson('/api/v1/payments/receipts', [
            'customer_id' => $this->customer->id,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'TT-528-'.Str::random(4),
            'applications' => $applications,
        ])->assertSuccessful();
    }

    private function postedCreditNote(Invoice $invoice, float $amount): int
    {
        $created = $this->as($this->admin, (int) $invoice->legal_entity_id)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'Issue528 credit',
            'lines' => [[
                'description' => 'Credit against '.$invoice->invoice_number,
                'quantity' => 1,
                'unit_price' => $amount,
                'tax_rate' => 0,
            ]],
        ]);
        $created->assertCreated($created->getContent());
        $id = (int) $created->json('id');

        $this->as($this->admin, (int) $invoice->legal_entity_id)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$id}/post")
            ->assertOk();

        return $id;
    }

    /** A fully paid invoice of 100 credited 60: the 60 is customer credit. */
    private function creditNoteHoldingSixty(): int
    {
        $origin = $this->postedInvoice(100);
        $this->receipt(100, [['invoice_id' => $origin->id, 'amount' => 100]]);
        $creditNoteId = $this->postedCreditNote($origin, 60);

        $this->assertSame(60.0, $this->remainingCredit($creditNoteId));
        $this->assertSame(60.0, $this->customerCredit());

        return $creditNoteId;
    }

    private function bankAccount(string $currency): int
    {
        DB::table('currencies')->updateOrInsert(
            ['code' => $currency],
            ['name' => $currency, 'symbol' => $currency, 'decimal_places' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        return (int) DB::table('bank_accounts')->insertGetId([
            'account_name' => "Issue528 {$currency} account",
            'account_number' => Str::random(8),
            'bank_name' => 'Issue528 Bank',
            'currency_code' => $currency,
            'gl_account_id' => $this->bankGlAccountId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function noteCurrency(int $creditNoteId): string
    {
        $note = CustomerCreditNote::findOrFail($creditNoteId);

        return strtoupper((string) ($note->currency_code ?: $note->base_currency_code ?: 'MYR'));
    }

    private function refund(int $creditNoteId, float $amount, ?int $bankAccountId = null, ?User $user = null, ?int $entityId = null): TestResponse
    {
        return $this->as($user ?? $this->admin, $entityId)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/refunds", [
                'amount' => $amount,
                'bank_account_id' => $bankAccountId ?? $this->bankAccount($this->noteCurrency($creditNoteId)),
                'method' => 'bank_transfer',
                'refund_date' => now()->toDateString(),
                'reference' => 'RF-528',
            ]);
    }

    private function remainingCredit(int $creditNoteId): float
    {
        $response = $this->as($this->admin)->getJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}");
        $response->assertOk();

        return round((float) ($response->json('outcome.remaining_credit') ?? $response->json('data.outcome.remaining_credit')), 2);
    }

    private function customerCredit(): float
    {
        return round((float) $this->customer->fresh()->credit_balance, 2);
    }

    private function subledger(int $entityId): float
    {
        return round((float) DB::table('ar_transactions')
            ->where('legal_entity_id', $entityId)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS b')
            ->value('b'), 2);
    }

    private function ledger(int $entityId, ?int $accountId = null): float
    {
        return round((float) DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId ?? $this->arAccountId)
            ->where('e.status', 'posted')
            ->where('e.legal_entity_id', $entityId)
            ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) AS b')
            ->value('b'), 2);
    }

    private function assertBooksReconcile(string $message): void
    {
        foreach ([$this->entityA, $this->entityB] as $entityId) {
            $this->assertSame($this->subledger($entityId), $this->ledger($entityId), "{$message}: AR subledger must equal the AR ledger (entity {$entityId})");
        }

        $unbalanced = DB::table('journal_entry_lines')
            ->select('journal_entry_id')
            ->groupBy('journal_entry_id')
            ->havingRaw('ROUND(SUM(debit) - SUM(credit), 2) <> 0')
            ->count();
        $this->assertSame(0, $unbalanced, "{$message}: every journal entry balances");
    }

    public function test_refund_pays_out_remaining_credit_through_the_ledger(): void
    {
        $creditNoteId = $this->creditNoteHoldingSixty();
        $arBefore = $this->ledger($this->entityA);
        $bankBefore = $this->ledger($this->entityA, $this->bankGlAccountId);

        $response = $this->refund($creditNoteId, 40);
        $response->assertCreated();

        $this->assertSame(20.0, $this->remainingCredit($creditNoteId), 'remaining credit drops by the refunded 40');
        $this->assertSame(20.0, $this->customerCredit(), 'customer credit balance drops by the refunded 40');

        // The refund is an outbound payment posted through the PostingEngine:
        // Dr Accounts Receivable 40 / Cr Bank 40, one balanced journal entry.
        $journalEntryId = (int) $response->json('data.journal_entry_id');
        $this->assertGreaterThan(0, $journalEntryId);
        $lines = DB::table('journal_entry_lines')->where('journal_entry_id', $journalEntryId)->get();
        $this->assertEqualsWithDelta(40.0, (float) $lines->sum('debit'), 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $lines->sum('credit'), 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $lines->where('account_id', $this->arAccountId)->sum('debit'), 0.001, 'Dr AR');
        $this->assertEqualsWithDelta(40.0, (float) $lines->where('account_id', $this->bankGlAccountId)->sum('credit'), 0.001, 'Cr Bank');

        $this->assertSame(round($arBefore + 40, 2), $this->ledger($this->entityA), 'AR ledger moves up by the refund (credit paid out)');
        $this->assertSame(round($bankBefore - 40, 2), $this->ledger($this->entityA, $this->bankGlAccountId), 'bank goes down by the refund');
        $this->assertBooksReconcile('after refund');

        // AR now carries only the 20 customer credit still held by the note.
        $this->assertSame(-20.0, $this->subledger($this->entityA));

        // The rest of the credit can still be applied, but not beyond what is left.
        $target = $this->postedInvoice(300);
        $this->as($this->admin)->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/applications", [
            'applications' => [['invoice_id' => $target->id, 'amount' => 20.01]],
        ])->assertStatus(422);
        $this->as($this->admin)->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/applications", [
            'applications' => [['invoice_id' => $target->id, 'amount' => 20]],
        ])->assertOk();

        $this->assertSame(0.0, $this->remainingCredit($creditNoteId));
        $this->assertSame(0.0, $this->customerCredit());
        $this->assertEqualsWithDelta(280.0, (float) $target->fresh()->balance, 0.001);
        $this->assertBooksReconcile('after applying the rest');
    }

    public function test_refund_cannot_exceed_remaining_credit(): void
    {
        $creditNoteId = $this->creditNoteHoldingSixty();
        $journalsBefore = DB::table('journal_entries')->count();

        $this->refund($creditNoteId, 60.01)->assertStatus(422);
        $this->refund($creditNoteId, 0)->assertStatus(422);

        $this->refund($creditNoteId, 60)->assertCreated();
        $this->refund($creditNoteId, 0.01)->assertStatus(422);

        $this->assertSame($journalsBefore + 1, DB::table('journal_entries')->count(), 'only the valid refund posted');
        $this->assertSame(0.0, $this->remainingCredit($creditNoteId));
        $this->assertSame(0.0, $this->customerCredit());

        // A note whose credit was fully absorbed by its own unpaid invoice holds
        // no credit to refund.
        $unpaid = $this->postedInvoice(100);
        $absorbed = $this->postedCreditNote($unpaid, 60);
        $this->assertSame(0.0, $this->remainingCredit($absorbed));
        $this->refund($absorbed, 1)->assertStatus(422);

        $this->assertBooksReconcile('after refused refunds');
    }

    public function test_cross_entity_and_cross_currency_refunds_are_refused(): void
    {
        $creditNoteId = $this->creditNoteHoldingSixty();
        $journalsBefore = DB::table('journal_entries')->count();

        // Credit held by entity A cannot be paid out from entity B's context.
        $this->refund($creditNoteId, 10, null, $this->admin, $this->entityB)->assertStatus(422);

        // Credit held in the note's currency cannot be paid from an account in another currency.
        $otherCurrency = $this->noteCurrency($creditNoteId) === 'EUR' ? 'GBP' : 'EUR';
        $this->refund($creditNoteId, 10, $this->bankAccount($otherCurrency))->assertStatus(422);

        $this->assertSame($journalsBefore, DB::table('journal_entries')->count(), 'no journal for refused refunds');
        $this->assertSame(60.0, $this->remainingCredit($creditNoteId));
        $this->assertSame(60.0, $this->customerCredit());
        $this->assertBooksReconcile('after refused refunds');
    }

    public function test_a_viewer_cannot_refund(): void
    {
        $creditNoteId = $this->creditNoteHoldingSixty();

        $this->refund($creditNoteId, 10, null, $this->viewer)->assertForbidden();

        $this->assertSame(60.0, $this->remainingCredit($creditNoteId));
    }

    public function test_voiding_the_refund_restores_the_credit(): void
    {
        $creditNoteId = $this->creditNoteHoldingSixty();
        $arBefore = $this->ledger($this->entityA);

        $refund = $this->refund($creditNoteId, 60);
        $refund->assertCreated();
        $refundId = (int) $refund->json('data.id');
        $this->assertSame(0.0, $this->remainingCredit($creditNoteId));

        // The credit note cannot be voided while a live refund holds its credit.
        $this->as($this->admin)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/void", ['reason' => 'Issue528'])
            ->assertStatus(422);
        $this->assertSame('posted', CustomerCreditNote::findOrFail($creditNoteId)->status);

        $this->as($this->admin)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/refunds/{$refundId}/void", ['reason' => 'Issue528 wrong account'])
            ->assertOk();

        $this->assertSame(60.0, $this->remainingCredit($creditNoteId), 'void restores the remaining credit');
        $this->assertSame(60.0, $this->customerCredit(), 'void restores the customer credit balance');
        $this->assertSame($arBefore, $this->ledger($this->entityA), 'the refund journal is reversed');
        $this->assertBooksReconcile('after voiding the refund');

        // A voided refund cannot be voided twice.
        $this->as($this->admin)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/refunds/{$refundId}/void")
            ->assertStatus(422);

        // With the refund voided, the credit note itself can be voided.
        $this->as($this->admin)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/void", ['reason' => 'Issue528'])
            ->assertOk();
        $this->assertSame(0.0, $this->customerCredit());
        $this->assertBooksReconcile('after voiding the credit note');
    }
}

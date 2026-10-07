<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #495 (SALES-ODOO): "Outstanding credits" on an invoice — the
 * customer's unapplied payments AND the remaining credit of their posted
 * credit notes can be applied (full or partial) to an open invoice through
 * PaymentAllocationService.
 *
 * Everything runs through the real router → middleware → controller, with
 * invoices, receipts and credit notes posted to the GL by their real routes
 * (PostingEngine, never mocked). Business rules asserted:
 *  - invoice balance = total − paid − credited after applying either kind;
 *  - the credit source's remaining unapplied amount drops by the applied amount;
 *  - cross-customer and cross-entity applications are refused (422, no change);
 *  - AR subledger == ledger (per entity) and the open items reconcile to it;
 *  - voiding an applied credit note releases the credit from the target invoice.
 */
class Issue495OutstandingCreditsTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private User $admin;

    private User $viewer;

    private Customer $customer;

    private int $arAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue495 Outstanding Credits Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue495 Group',
            'code' => 'I495-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['A', 'B'] as $suffix) {
            $this->{'entity'.$suffix} = DB::table('legal_entities')->insertGetId([
                'group_id' => $groupId,
                'name' => "Entity {$suffix}",
                'code' => "495-{$suffix}-".Str::upper(Str::random(5)),
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

        // No customer-level entity: each document's entity comes from the request.
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

    private function postedInvoice(float $total, ?int $entityId = null, ?Customer $customer = null): Invoice
    {
        $entityId ??= $this->entityA;

        $created = $this->as($this->admin, $entityId)->postJson('/api/v1/invoices', [
            'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'description' => 'Issue495 Widget',
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

    /** Record + post a receipt; with no applications it stays unapplied credit. */
    private function receipt(float $amount, array $applications = [], ?int $entityId = null): int
    {
        $response = $this->as($this->admin, $entityId)->postJson('/api/v1/payments/receipts', [
            'customer_id' => $this->customer->id,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'TT-495-'.Str::random(4),
            'applications' => $applications,
        ]);
        $response->assertSuccessful();

        return (int) ($response->json('data.id') ?? $response->json('id'));
    }

    /** Create + post a credit note of $amount against $invoice. */
    private function postedCreditNote(Invoice $invoice, float $amount): int
    {
        $created = $this->as($this->admin, (int) $invoice->legal_entity_id)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'Issue495 credit',
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

    private function applyCreditNote(int $creditNoteId, int $invoiceId, float $amount, ?User $user = null): TestResponse
    {
        return $this->as($user ?? $this->admin)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/applications", [
                'applications' => [['invoice_id' => $invoiceId, 'amount' => $amount]],
            ]);
    }

    private function remainingCredit(int $creditNoteId): float
    {
        $response = $this->as($this->admin)->getJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}");
        $response->assertOk();

        return round((float) ($response->json('outcome.remaining_credit') ?? $response->json('data.outcome.remaining_credit')), 2);
    }

    private function assertInvoiceBalanceRule(Invoice $invoice, float $expectedBalance, string $message): void
    {
        $invoice->refresh();
        // #510 contract: credit beyond the open amount is customer credit,
        // never a negative balance.
        $rule = round(max(0.0, (float) $invoice->total - (float) $invoice->paid_amount - (float) $invoice->credited_amount), 2);

        $this->assertEqualsWithDelta($expectedBalance, $rule, 0.001, "{$message}: max(0, total − paid − credited)");
        $this->assertEqualsWithDelta($expectedBalance, (float) $invoice->balance, 0.001, "{$message}: stored balance must follow total − paid − credited");
    }

    private function subledger(int $entityId): float
    {
        return round((float) DB::table('ar_transactions')
            ->where('legal_entity_id', $entityId)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS b')
            ->value('b'), 2);
    }

    private function ledger(int $entityId): float
    {
        return round((float) DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $this->arAccountId)
            ->where('e.status', 'posted')
            ->where('e.legal_entity_id', $entityId)
            ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) AS b')
            ->value('b'), 2);
    }

    public function test_credit_note_remaining_credit_and_unapplied_payment_apply_to_an_open_invoice(): void
    {
        // Origin invoice fully paid, then credited 60: the 60 is customer credit.
        $origin = $this->postedInvoice(100);
        $this->receipt(100, [['invoice_id' => $origin->id, 'amount' => 100]]);
        $creditNoteId = $this->postedCreditNote($origin, 60);
        $this->assertSame(60.0, $this->remainingCredit($creditNoteId));
        $this->assertSame(60.0, round((float) $this->customer->fresh()->credit_balance, 2));

        $target = $this->postedInvoice(300);

        // Partial application of the credit note.
        $this->applyCreditNote($creditNoteId, $target->id, 40)->assertOk();

        $this->assertInvoiceBalanceRule($target, 260.0, 'credit note applied to the target');
        $this->assertSame(20.0, $this->remainingCredit($creditNoteId), 'credit note remaining drops by the applied 40');
        $this->assertSame(20.0, round((float) $this->customer->fresh()->credit_balance, 2), 'customer credit drops by the applied 40');
        $this->assertInvoiceBalanceRule($origin, 0.0, 'origin invoice stays settled');

        // An unapplied receipt applied on the same invoice.
        $paymentId = $this->receipt(50);
        $journalsBefore = DB::table('journal_entries')->count();
        $this->as($this->admin)->postJson("/api/v1/payments/{$paymentId}/allocations", [
            'applications' => [['invoice_id' => $target->id, 'amount' => 50]],
        ])->assertOk()->assertJsonPath('unapplied_amount', 0);

        $this->assertInvoiceBalanceRule($target, 210.0, 'payment applied on top of the credit');

        // Remaining credit applied in full.
        $this->applyCreditNote($creditNoteId, $target->id, 20)->assertOk();
        $this->assertInvoiceBalanceRule($target, 190.0, 'remaining credit applied');
        $this->assertSame(0.0, $this->remainingCredit($creditNoteId));
        $this->assertSame(0.0, round((float) $this->customer->fresh()->credit_balance, 2));

        // Applying is a subledger reallocation: no journal entry, and the AR
        // subledger still equals the ledger — and the open items explain it.
        $this->assertSame($journalsBefore, DB::table('journal_entries')->count(), 'applying credit posts no journal');
        $subledger = $this->subledger($this->entityA);
        $this->assertSame(190.0, $subledger, 'AR = 400 invoiced − 150 received − 60 credited');
        $this->assertSame($subledger, $this->ledger($this->entityA), 'AR subledger must equal the AR ledger');

        $openItems = round(
            (float) Invoice::whereIn('id', [$origin->id, $target->id])->sum('balance')
            - (float) $this->customer->fresh()->credit_balance,
            2
        );
        $this->assertSame($subledger, $openItems, 'open invoice balances − customer credit reconcile to the subledger');
    }

    public function test_application_beyond_remaining_credit_or_open_balance_is_refused(): void
    {
        $origin = $this->postedInvoice(100);
        $this->receipt(100, [['invoice_id' => $origin->id, 'amount' => 100]]);
        $creditNoteId = $this->postedCreditNote($origin, 60);
        $small = $this->postedInvoice(30);
        $large = $this->postedInvoice(500);

        $this->applyCreditNote($creditNoteId, $large->id, 60.01)->assertStatus(422);
        $this->applyCreditNote($creditNoteId, $small->id, 31)->assertStatus(422);
        $this->applyCreditNote($creditNoteId, $origin->id, 10)->assertStatus(422);

        $this->assertInvoiceBalanceRule($large, 500.0, 'refused over-application');
        $this->assertInvoiceBalanceRule($small, 30.0, 'refused over-balance application');
        $this->assertSame(60.0, $this->remainingCredit($creditNoteId));
    }

    public function test_cross_customer_and_cross_entity_credits_are_refused(): void
    {
        $origin = $this->postedInvoice(100);
        $this->receipt(100, [['invoice_id' => $origin->id, 'amount' => 100]]);
        $creditNoteId = $this->postedCreditNote($origin, 60);

        $otherCustomer = Customer::factory()->create(['is_active' => true]);
        $otherCustomersInvoice = $this->postedInvoice(200, $this->entityA, $otherCustomer);
        $otherEntityInvoice = $this->postedInvoice(200, $this->entityB);

        $this->applyCreditNote($creditNoteId, $otherCustomersInvoice->id, 10)->assertStatus(422);
        $this->applyCreditNote($creditNoteId, $otherEntityInvoice->id, 10)->assertStatus(422);

        // An unapplied receipt held by entity A cannot settle entity B's receivable.
        $paymentId = $this->receipt(50, [], $this->entityA);
        $this->as($this->admin)->postJson("/api/v1/payments/{$paymentId}/allocations", [
            'applications' => [['invoice_id' => $otherEntityInvoice->id, 'amount' => 50]],
        ])->assertStatus(422);

        $this->assertInvoiceBalanceRule($otherCustomersInvoice, 200.0, 'cross-customer refused');
        $this->assertInvoiceBalanceRule($otherEntityInvoice, 200.0, 'cross-entity refused');
        $this->assertSame(60.0, $this->remainingCredit($creditNoteId));
        $this->assertSame($this->subledger($this->entityA), $this->ledger($this->entityA));
        $this->assertSame($this->subledger($this->entityB), $this->ledger($this->entityB));
    }

    public function test_a_viewer_cannot_apply_credit(): void
    {
        $origin = $this->postedInvoice(100);
        $this->receipt(100, [['invoice_id' => $origin->id, 'amount' => 100]]);
        $creditNoteId = $this->postedCreditNote($origin, 60);
        $target = $this->postedInvoice(300);

        $this->applyCreditNote($creditNoteId, $target->id, 40, $this->viewer)->assertForbidden();

        $this->assertInvoiceBalanceRule($target, 300.0, 'viewer refused');
        $this->assertSame(60.0, $this->remainingCredit($creditNoteId));
    }

    public function test_voiding_an_applied_credit_note_releases_the_applied_credit(): void
    {
        $origin = $this->postedInvoice(100);
        $this->receipt(100, [['invoice_id' => $origin->id, 'amount' => 100]]);
        $creditNoteId = $this->postedCreditNote($origin, 60);
        $target = $this->postedInvoice(300);

        $this->applyCreditNote($creditNoteId, $target->id, 40)->assertOk();

        $this->as($this->admin)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/void", ['reason' => 'Issue495 void'])
            ->assertOk();

        $this->assertInvoiceBalanceRule($target, 300.0, 'void releases the applied credit');
        $this->assertInvoiceBalanceRule($origin, 0.0, 'origin back to paid in full');
        $this->assertSame(0.0, round((float) $this->customer->fresh()->credit_balance, 2));
        $this->assertSame($this->subledger($this->entityA), $this->ledger($this->entityA));
    }

    /**
     * #528: the credit note detail applies credit from the note's side. It
     * needs the note's applications (with invoice numbers) on the detail
     * payload and each candidate invoice's legal entity, so it only offers
     * invoices the backend would accept.
     */
    public function test_credit_note_detail_exposes_applications_and_candidate_invoices_carry_their_entity(): void
    {
        $origin = $this->postedInvoice(100);
        $this->receipt(100, [['invoice_id' => $origin->id, 'amount' => 100]]);
        $creditNoteId = $this->postedCreditNote($origin, 60);
        $target = $this->postedInvoice(300);
        $otherEntityInvoice = $this->postedInvoice(200, $this->entityB);

        $this->applyCreditNote($creditNoteId, $target->id, 25)->assertOk();

        $detail = $this->as($this->admin)->getJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}");
        $detail->assertOk();
        $applications = collect($detail->json('applications'));
        $this->assertCount(1, $applications);
        $this->assertSame($target->id, (int) $applications[0]['invoice_id']);
        $this->assertEqualsWithDelta(25.0, (float) $applications[0]['applied_amount'], 0.001);
        $this->assertSame($target->invoice_number, $applications[0]['invoice']['invoice_number']);
        $this->assertEqualsWithDelta(35.0, (float) $detail->json('outcome.remaining_credit'), 0.001);

        $eligible = collect($this->as($this->admin)
            ->getJson("/api/v1/payments/eligible-invoices?customer_id={$this->customer->id}")
            ->assertOk()
            ->json('data'))->keyBy('id');

        $this->assertSame($this->entityA, (int) $eligible[$target->id]['legal_entity_id']);
        $this->assertSame($this->entityB, (int) $eligible[$otherEntityInvoice->id]['legal_entity_id']);
    }
}

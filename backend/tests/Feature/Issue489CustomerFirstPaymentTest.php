<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #489 (PAY-UX3): customer-first payment entry. One receipt from a
 * customer is recorded, posted and allocated across their open invoices in a
 * single atomic request — POST /payments/receipts — reusing the canonical
 * PaymentPostingService (PostingEngine) and the #155 PaymentAllocationService.
 * Any remainder stays on the payment as unapplied credit.
 *
 * Business rules asserted (through the real router → middleware → controller):
 *  - one payment, one balanced journal entry crediting AR by the receipt;
 *  - each invoice balance = total − applied, status paid/partial;
 *  - payment unapplied = amount − Σ applied, listed by GET /payments?unapplied=1;
 *  - any invalid allocation rejects the WHOLE receipt (no payment, no JE);
 *  - posting requires accounting.post.
 */
class Issue489CustomerFirstPaymentTest extends TenantTestCase
{
    private string $tenantId;

    private User $accountant;

    private User $sales;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady(['payments', 'payment_applications', 'invoices', 'customers', 'journal_entries']);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT);
        $this->sales = $this->makeUser('sales', UserRole::SALES);
        $this->customer = Customer::factory()->create(['payment_terms' => 30]);
        $this->seedPostingFixtures($this->accountant->id);
    }

    private function makeUser(string $centralRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole->spatieName());

        return $user;
    }

    private function seedPostingFixtures(int $userId): void
    {
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
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['cash' => '1010', 'bank' => '1020', 'accounts_receivable' => '1100'] as $key => $accountCode) {
            if (DB::table('account_mappings')->where('mapping_key', $key)->whereNull('document_type')->exists()) {
                continue;
            }
            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => DB::table('chart_of_accounts')->where('code', $accountCode)->value('id'),
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function openInvoice(float $total, int $dueInDays, ?Customer $customer = null): Invoice
    {
        return Invoice::create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays($dueInDays)->toDateString(),
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'created_by' => $this->accountant->id,
        ]);
    }

    private function receipt(array $overrides = []): array
    {
        return $overrides + [
            'customer_id' => $this->customer->id,
            'amount' => 1000,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'TT-489',
        ];
    }

    public function test_one_receipt_is_posted_and_allocated_across_invoices_with_the_remainder_on_account(): void
    {
        // Oldest-due first: A (overdue) → B → C. RM 1,000 covers A and B in
        // full and RM 100 of C; nothing remains on account.
        $a = $this->openInvoice(300, -20);
        $b = $this->openInvoice(400, -5);
        $c = $this->openInvoice(500, 10);

        $response = $this->actingAs($this->accountant)
            ->postJson('/api/v1/payments/receipts', $this->receipt([
                'amount' => 1050,
                'applications' => [
                    ['invoice_id' => $a->id, 'amount' => 300],
                    ['invoice_id' => $b->id, 'amount' => 400],
                    ['invoice_id' => $c->id, 'amount' => 300],
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('applied_amount', 1000)
            ->assertJsonPath('unapplied_amount', 50);

        $this->assertSame(1, Payment::count(), 'A customer receipt is ONE payment.');
        $payment = Payment::findOrFail($response->json('id'));
        $this->assertSame('applied', $payment->payment_status);
        $this->assertNull($payment->invoice_id);
        $this->assertSame($this->customer->id, (int) $payment->customer_id);
        $this->assertNotNull($payment->journal_entry_id, 'The receipt is posted through the posting engine.');

        // GL: one balanced entry, AR credited by the full receipt.
        $entry = JournalEntry::with('lines')->findOrFail($payment->journal_entry_id);
        $this->assertSame(
            round((float) $entry->lines->sum('debit'), 2),
            round((float) $entry->lines->sum('credit'), 2),
        );
        $this->assertSame(1050.0, round((float) $entry->lines->sum('credit'), 2));

        // Subledger: balance = total − applied; statuses follow.
        $this->assertEqualsWithDelta(0.0, (float) $a->fresh()->balance, 0.001);
        $this->assertSame('paid', $a->fresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $b->fresh()->balance, 0.001);
        $this->assertSame('paid', $b->fresh()->status);
        $this->assertEqualsWithDelta(200.0, (float) $c->fresh()->balance, 0.001);
        $this->assertSame('partial', $c->fresh()->status);

        $applications = PaymentApplication::where('payment_id', $payment->id)->whereNull('reversed_at')->get()->keyBy('invoice_id');
        $this->assertCount(3, $applications);
        $this->assertEqualsWithDelta(300.0, (float) $applications[$a->id]->applied_amount, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $applications[$b->id]->applied_amount, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $applications[$c->id]->applied_amount, 0.001);

        // AR ↔ ledger: AR credited (1050) = applied to invoices (1000) + on account (50).
        $this->assertEqualsWithDelta(
            1050.0,
            (float) $applications->sum('applied_amount') + 50,
            0.001,
        );

        // The remainder is unapplied credit, visible to the #488 credit list.
        $rows = collect($this->actingAs($this->accountant)
            ->getJson('/api/v1/payments?unapplied=1&customer_id='.$this->customer->id)
            ->assertOk()
            ->json('data'))->keyBy('id');
        $this->assertEqualsWithDelta(50.0, (float) $rows[$payment->id]['unapplied_amount'], 0.001);
    }

    public function test_a_receipt_with_no_allocations_is_held_on_account(): void
    {
        $response = $this->actingAs($this->accountant)
            ->postJson('/api/v1/payments/receipts', $this->receipt(['amount' => 250]))
            ->assertCreated()
            ->assertJsonPath('applied_amount', 0)
            ->assertJsonPath('unapplied_amount', 250);

        $this->assertNotNull(Payment::findOrFail($response->json('id'))->journal_entry_id);
    }

    public function test_over_allocating_rejects_the_whole_receipt(): void
    {
        $a = $this->openInvoice(300, -20);
        $b = $this->openInvoice(400, -5);
        $journalCount = JournalEntry::count();

        $this->actingAs($this->accountant)
            ->postJson('/api/v1/payments/receipts', $this->receipt([
                'amount' => 500,
                'applications' => [
                    ['invoice_id' => $a->id, 'amount' => 300],
                    ['invoice_id' => $b->id, 'amount' => 300],
                ],
            ]))
            ->assertStatus(422);

        $this->assertSame(0, Payment::count(), 'No payment survives a rejected receipt.');
        $this->assertSame($journalCount, JournalEntry::count(), 'No journal entry survives a rejected receipt.');
        $this->assertEqualsWithDelta(300.0, (float) $a->fresh()->balance, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $b->fresh()->balance, 0.001);
    }

    public function test_an_invoice_of_another_customer_rejects_the_whole_receipt(): void
    {
        $mine = $this->openInvoice(300, -20);
        $theirs = $this->openInvoice(300, -20, Customer::factory()->create());
        $journalCount = JournalEntry::count();

        $this->actingAs($this->accountant)
            ->postJson('/api/v1/payments/receipts', $this->receipt([
                'amount' => 600,
                'applications' => [
                    ['invoice_id' => $mine->id, 'amount' => 300],
                    ['invoice_id' => $theirs->id, 'amount' => 300],
                ],
            ]))
            ->assertStatus(422);

        $this->assertSame(0, Payment::count());
        $this->assertSame($journalCount, JournalEntry::count(), 'No journal entry survives a rejected receipt.');
        $this->assertEqualsWithDelta(300.0, (float) $mine->fresh()->balance, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $theirs->fresh()->balance, 0.001);
    }

    public function test_partial_allocation_is_rejected_when_partial_payments_are_disabled(): void
    {
        Setting::setValue('sales.invoice.allow_partial_payments', false);
        $a = $this->openInvoice(300, -20);

        $this->actingAs($this->accountant)
            ->postJson('/api/v1/payments/receipts', $this->receipt([
                'amount' => 200,
                'applications' => [['invoice_id' => $a->id, 'amount' => 200]],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PARTIAL_PAYMENT_NOT_ALLOWED');

        $this->assertSame(0, Payment::count());
        $this->assertEqualsWithDelta(300.0, (float) $a->fresh()->balance, 0.001);
    }

    public function test_a_role_without_accounting_post_cannot_post_a_receipt(): void
    {
        $a = $this->openInvoice(300, -20);

        $this->actingAs($this->sales)
            ->postJson('/api/v1/payments/receipts', $this->receipt([
                'amount' => 300,
                'applications' => [['invoice_id' => $a->id, 'amount' => 300]],
            ]))
            ->assertForbidden();

        $this->assertSame(0, Payment::count());
        $this->assertEqualsWithDelta(300.0, (float) $a->fresh()->balance, 0.001);
    }

    public function test_a_foreign_currency_invoice_is_not_allocated_from_a_customer_receipt(): void
    {
        $base = \App\Services\DocumentCurrencyResolver::baseFor(null, $this->customer->id);
        $foreign = $this->openInvoice(300, -20);
        $foreign->forceFill(['currency_code' => $base === 'USD' ? 'EUR' : 'USD', 'exchange_rate' => 4.5])->save();

        $this->actingAs($this->accountant)
            ->postJson('/api/v1/payments/receipts', $this->receipt([
                'amount' => 300,
                'applications' => [['invoice_id' => $foreign->id, 'amount' => 300]],
            ]))
            ->assertStatus(422);

        $this->assertSame(0, Payment::count());
        $this->assertEqualsWithDelta(300.0, (float) $foreign->fresh()->balance, 0.001);
    }

    public function test_eligible_invoices_expose_due_date_and_foreign_flag_for_the_receipt_table(): void
    {
        $base = \App\Services\DocumentCurrencyResolver::baseFor(null, $this->customer->id);
        $local = $this->openInvoice(300, -20);
        $foreign = $this->openInvoice(100, -5);
        $foreign->forceFill(['currency_code' => $base === 'USD' ? 'EUR' : 'USD', 'exchange_rate' => 4.5])->save();

        $rows = collect($this->actingAs($this->accountant)
            ->getJson('/api/v1/payments/eligible-invoices?customer_id='.$this->customer->id)
            ->assertOk()
            ->json('data'))->keyBy('id');

        $this->assertSame(now()->subDays(20)->toDateString(), substr((string) $rows[$local->id]['due_date'], 0, 10));
        $this->assertFalse($rows[$local->id]['foreign']);
        $this->assertTrue($rows[$foreign->id]['foreign']);
    }
}

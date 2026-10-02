<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #478 (decision 2026-10-02, product owner): implement payment cash discounts
 * end to end. An invoice for 1,000 paid with 980 cash + a 20 discount must:
 *   - settle the invoice in full (paid_amount 1,000, balance 0, status paid);
 *   - post debit Bank/Cash 980, debit Sales discount 20, credit AR 1,000;
 *   - be undone fully by a reversal (discount leg included).
 *
 * Before: store() accepted discount_amount and checked amount+discount against
 * the balance, but settlement and posting used only `amount` — the money was
 * silently lost (invoice stayed partial with 20 due, no discount in the GL).
 *
 * Output-tax treatment of cash discounts is NOT applied here: it depends on the
 * country pack (#285 gap) and requires a tax-agent decision — out of scope.
 *
 * Exercises the real router/middleware/controller chain via the HTTP API.
 */
class PaymentCashDiscountTest extends TenantTestCase
{
    protected User $admin;

    protected Customer $customer;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['payment_terms' => 30]);
        $this->seedPostingFixtures($this->admin->id);
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

        foreach (['cash' => '1010', 'bank' => '1020', 'accounts_receivable' => '1100', 'sales_discount' => '4200'] as $key => $accountCode) {
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

    private function openInvoice(float $total = 1000): Invoice
    {
        return Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'created_by' => $this->admin->id,
        ]);
    }

    private function recordDraft(Invoice $invoice, float $amount, float $discount): Payment
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'discount_amount' => $discount,
                'method' => 'cash',
            ])
            ->assertCreated();

        return Payment::findOrFail($response->json('id'));
    }

    public function test_posting_a_discounted_payment_settles_the_invoice_and_posts_the_discount_leg(): void
    {
        $invoice = $this->openInvoice(1000);
        $payment = $this->recordDraft($invoice, 980, 20);

        // Draft: nothing touches the invoice yet (#477).
        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->paid_amount);
        $this->assertSame(1000.0, (float) $invoice->balance);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment->id}/post")
            ->assertOk();

        $payment->refresh();
        $this->assertNotNull($payment->journal_entry_id);

        // Settlement uses amount + discount.
        $invoice->refresh();
        $this->assertSame(1000.0, (float) $invoice->paid_amount, 'Discount must settle part of the invoice.');
        $this->assertSame(0.0, (float) $invoice->balance, 'Invoice must be fully settled.');
        $this->assertSame('paid', $invoice->status);

        // GL: debit Bank 980, debit Sales discount 20, credit AR 1,000.
        $entry = JournalEntry::with('lines')->find($payment->journal_entry_id);
        $this->assertSame(
            round((float) $entry->lines->sum('debit'), 2),
            round((float) $entry->lines->sum('credit'), 2),
            'Payment journal entry must balance.',
        );
        $this->assertSame(1000.0, round((float) $entry->lines->sum('debit'), 2));
        $this->assertTrue(
            $entry->lines->contains(fn ($l) => round((float) $l->debit, 2) === 980.0),
            'Bank/Cash must be debited by the cash received (980).',
        );
        $this->assertTrue(
            $entry->lines->contains(fn ($l) => round((float) $l->debit, 2) === 20.0),
            'Sales discount must be debited by the discount granted (20).',
        );
        $this->assertTrue(
            $entry->lines->contains(fn ($l) => round((float) $l->credit, 2) === 1000.0),
            'AR must be credited by amount + discount (1,000).',
        );
    }

    public function test_reversing_a_discounted_payment_undoes_the_discount_leg(): void
    {
        $invoice = $this->openInvoice(1000);
        $payment = $this->recordDraft($invoice, 980, 20);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment->id}/post")
            ->assertOk();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/corrections/payments/{$payment->id}/reverse", [
                'reason_code' => 'data_entry_error',
                'reason_text' => 'Reproduced correction',
            ])
            ->assertOk();

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->paid_amount, 'Reversal must restore the invoice.');
        $this->assertSame(1000.0, (float) $invoice->balance);

        $reversalEntryId = $response->json('reversal_journal_entry.id');
        $this->assertNotNull($reversalEntryId, 'A reversal journal entry is expected.');

        $entry = JournalEntry::with('lines')->find($reversalEntryId);
        $this->assertSame(
            round((float) $entry->lines->sum('debit'), 2),
            round((float) $entry->lines->sum('credit'), 2),
            'Reversal entry must balance.',
        );
        // Reversing the discount leg: AR debited 1,000; Bank credited 980;
        // Sales discount credited 20.
        $this->assertTrue(
            $entry->lines->contains(fn ($l) => round((float) $l->debit, 2) === 1000.0),
            'Reversal must debit AR by amount + discount.',
        );
        $this->assertTrue(
            $entry->lines->contains(fn ($l) => round((float) $l->credit, 2) === 980.0),
            'Reversal must credit Bank by the cash amount.',
        );
        $this->assertTrue(
            $entry->lines->contains(fn ($l) => round((float) $l->credit, 2) === 20.0),
            'Reversal must credit the sales discount leg.',
        );
    }
}
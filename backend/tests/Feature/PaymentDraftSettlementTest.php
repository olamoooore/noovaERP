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
 * #477 (Decision 2026-10-02): a DRAFT (unposted) payment must not touch the
 * invoice. paid_amount, balance, status and AR move only together with the
 * journal entry — i.e. only when the payment is posted. Record / edit / delete
 * of a draft all leave the invoice untouched; posting settles it.
 *
 * Exercises the real router/middleware/controller chain via the HTTP API.
 */
class PaymentDraftSettlementTest extends TenantTestCase
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

    private function openInvoice(float $total = 500): Invoice
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

    private function recordDraft(Invoice $invoice, float $amount): Payment
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => 'cash',
            ])
            ->assertCreated();

        return Payment::findOrFail($response->json('id'));
    }

    public function test_recording_a_draft_payment_does_not_touch_the_invoice(): void
    {
        $invoice = $this->openInvoice(500);

        $payment = $this->recordDraft($invoice, 200);

        $this->assertNull($payment->journal_entry_id, 'A recorded payment is a draft — no journal entry yet.');

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->paid_amount, 'Draft must not increase paid_amount.');
        $this->assertSame(500.0, (float) $invoice->balance, 'Draft must not reduce the balance.');
        $this->assertSame('sent', $invoice->status, 'Draft must not flip the invoice to paid/partial.');
    }

    public function test_posting_a_draft_settles_the_invoice_and_credits_ar(): void
    {
        $invoice = $this->openInvoice(500);
        $payment = $this->recordDraft($invoice, 200);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment->id}/post")
            ->assertOk();

        $payment->refresh();
        $this->assertNotNull($payment->journal_entry_id, 'Posting must create the journal entry.');

        $invoice->refresh();
        $this->assertSame(200.0, (float) $invoice->paid_amount, 'Posting must apply the payment.');
        $this->assertSame(300.0, (float) $invoice->balance, 'Balance = total - posted payments.');
        $this->assertSame('partial', $invoice->status);

        // GL: the entry balances and AR is credited by the payment amount.
        $entry = JournalEntry::with('lines')->find($payment->journal_entry_id);
        $this->assertSame(
            round((float) $entry->lines->sum('debit'), 2),
            round((float) $entry->lines->sum('credit'), 2),
            'Payment journal entry must balance.',
        );
        $this->assertSame(
            200.0,
            round((float) $entry->lines->sum('credit'), 2),
            'AR credit (and total credits) must equal the posted payment amount.',
        );
    }

    public function test_deleting_a_draft_leaves_the_invoice_untouched(): void
    {
        $invoice = $this->openInvoice(500);
        $payment = $this->recordDraft($invoice, 200);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/payments/{$payment->id}")
            ->assertOk();

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->paid_amount, 'Deleting a draft must not change paid_amount.');
        $this->assertSame(500.0, (float) $invoice->balance, 'Deleting a draft must not change the balance.');
    }

    public function test_editing_a_draft_amount_does_not_change_invoice_balance(): void
    {
        $invoice = $this->openInvoice(500);
        $payment = $this->recordDraft($invoice, 200);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/payments/{$payment->id}", ['amount' => 300])
            ->assertOk();

        $this->assertSame(300.0, (float) $payment->fresh()->amount);

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->paid_amount, 'Editing a draft must not apply it to the invoice.');
        $this->assertSame(500.0, (float) $invoice->balance);
    }
}

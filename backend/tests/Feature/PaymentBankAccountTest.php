<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
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
 * #485: multi-bank tenants must be able to record WHICH account received the
 * money. The backend accepts bank_account_id on CREATE and posts the debit to
 * that account's GL — but update() rejected it (422) and show() returned no
 * bank-account detail, so the edit dialog could never change the deposit
 * account and the detail view could never display it. The forms send nothing
 * (fixed separately in the same issue, frontend side).
 *
 * Exercises the real router/middleware/controller chain via the HTTP API.
 */
class PaymentBankAccountTest extends TenantTestCase
{
    protected User $admin;

    protected Customer $customer;

    protected string $tenantId;

    protected BankAccount $bankAccount;

    protected ChartOfAccount $collectionGl;

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

        // A SECOND bank account on its own GL (the point of the issue: money
        // must land here, not on the default 1020 bank mapping).
        $this->collectionGl = ChartOfAccount::factory()->create([
            'code' => '1021',
            'name' => 'CIMB Collections',
            'type' => 'asset',
            'is_active' => true,
        ]);
        $this->bankAccount = BankAccount::create([
            'account_name' => 'CIMB Collections',
            'bank_name' => 'CIMB',
            'gl_account_id' => $this->collectionGl->id,
        ]);
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

    private function recordDraft(Invoice $invoice, float $amount, ?int $bankAccountId): Payment
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/payments', array_filter([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => 'bank_transfer',
                'bank_account_id' => $bankAccountId,
            ], fn ($v) => $v !== null))
            ->assertCreated();

        return Payment::findOrFail($response->json('id'));
    }

    public function test_draft_payment_update_persists_the_selected_bank_account(): void
    {
        $payment = $this->recordDraft($this->openInvoice(), 1000, null);
        $this->assertNull($payment->bank_account_id);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/payments/{$payment->id}", ['bank_account_id' => $this->bankAccount->id])
            ->assertOk();

        $this->assertSame(
            $this->bankAccount->id,
            (int) $payment->fresh()->bank_account_id,
            'update() must persist the deposit account chosen in the edit dialog',
        );
    }

    public function test_payment_detail_returns_the_receiving_bank_account(): void
    {
        $payment = $this->recordDraft($this->openInvoice(), 500, $this->bankAccount->id);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/payments/{$payment->id}")->assertOk();

        $response->assertJsonPath('bank_account_id', $this->bankAccount->id);
        $response->assertJsonPath('bank_account.account_name', 'CIMB Collections');
        $response->assertJsonPath('bank_account.gl_account_code', '1021');
    }

    public function test_posting_debits_the_selected_bank_account_and_keeps_the_entry_balanced(): void
    {
        $invoice = $this->openInvoice(1000);
        $payment = $this->recordDraft($invoice, 1000, $this->bankAccount->id);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment->id}/post")
            ->assertOk();

        $payment->refresh();
        $this->assertNotNull($payment->journal_entry_id, 'payment must be posted');

        $lines = JournalEntry::find($payment->journal_entry_id)->lines()->get();
        $debit = $lines->first(fn ($l) => (float) $l->debit > 0);
        $this->assertSame(
            $this->collectionGl->id,
            (int) $debit->account_id,
            'the receipt must be debited to the SELECTED bank GL, not the default 1020 mapping',
        );
        $this->assertEqualsWithDelta(1000.0, (float) $debit->debit, 0.001);

        $this->assertEqualsWithDelta(
            0.0,
            $lines->sum(fn ($l) => (float) $l->debit) - $lines->sum(fn ($l) => (float) $l->credit),
            0.001,
            'books must balance',
        );
    }
}

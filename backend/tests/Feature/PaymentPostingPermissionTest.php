<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #480: posting a payment to the GL must require accounting.post — the same
 * gate invoices and payment corrections already use. MANAGER has accounting
 * write but not post; ACCOUNTANT can post.
 *
 * Exercises the real router/middleware/controller chain via the HTTP API.
 */
class PaymentPostingPermissionTest extends TenantTestCase
{
    protected User $manager;

    protected User $accountant;

    protected Customer $customer;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->manager = $this->makeUser('manager', UserRole::MANAGER);
        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT);
        $this->customer = Customer::factory()->create(['payment_terms' => 30]);
        $this->seedPostingFixtures($this->accountant->id);
    }

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
            'created_by' => $this->accountant->id,
        ]);
    }

    private function recordDraft(Invoice $invoice, float $amount): Payment
    {
        $response = $this->actingAs($this->accountant)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => 'cash',
            ])
            ->assertCreated();

        return Payment::findOrFail($response->json('id'));
    }

    public function test_manager_cannot_post_a_payment(): void
    {
        $payment = $this->recordDraft($this->openInvoice(), 200);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/payments/{$payment->id}/post")
            ->assertForbidden();

        $this->assertNull($payment->fresh()->journal_entry_id, 'Manager must not reach the ledger.');
    }

    public function test_accountant_can_post_a_payment(): void
    {
        $invoice = $this->openInvoice();
        $payment = $this->recordDraft($invoice, 200);

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payments/{$payment->id}/post")
            ->assertOk();

        $this->assertNotNull($payment->fresh()->journal_entry_id);
        $this->assertSame(200.0, (float) $invoice->fresh()->paid_amount);
    }

    public function test_manager_cannot_bulk_post_payments(): void
    {
        $a = $this->recordDraft($this->openInvoice(), 100);
        $b = $this->recordDraft($this->openInvoice(), 100);

        $this->actingAs($this->manager)
            ->postJson('/api/v1/payments/bulk-post', ['ids' => [$a->id, $b->id]])
            ->assertForbidden();

        $this->assertNull($a->fresh()->journal_entry_id);
        $this->assertNull($b->fresh()->journal_entry_id);
    }

    public function test_accountant_can_bulk_post_payments(): void
    {
        $a = $this->recordDraft($this->openInvoice(), 100);
        $b = $this->recordDraft($this->openInvoice(), 100);

        $this->actingAs($this->accountant)
            ->postJson('/api/v1/payments/bulk-post', ['ids' => [$a->id, $b->id]])
            ->assertOk();

        $this->assertNotNull($a->fresh()->journal_entry_id);
        $this->assertNotNull($b->fresh()->journal_entry_id);
    }
}

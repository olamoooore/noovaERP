<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #479: the "exceeds bank account balance" guard is an OUTFLOW rule and must
 * not block incoming customer receipts. A brand-new (zero-balance) bank account
 * must be able to receive a 5,000 deposit.
 *
 * Exercises the real router/middleware/controller chain via the HTTP API.
 */
class PaymentReceiptBankBalanceTest extends TenantTestCase
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

        // Open fiscal period for today — the lock-date service is fail-closed.
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
    }

    public function test_a_zero_balance_bank_account_accepts_a_customer_receipt(): void
    {
        $bank = BankAccount::create([
            'account_name' => 'New Receiving Account',
            'account_number' => 'REC-'.Str::upper(Str::random(6)),
            'bank_name' => 'Test Bank',
            'currency_code' => 'USD',
            'gl_account_id' => DB::table('chart_of_accounts')->orderBy('id')->value('id'),
            'is_active' => true,
        ]);

        $this->assertSame(0.0, $bank->getCurrentBalance(), 'Fixture account starts empty.');

        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'subtotal' => 5000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 5000,
            'paid_amount' => 0,
            'balance' => 5000,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 5000,
                'method' => 'bank_transfer',
                'bank_account_id' => $bank->id,
            ]);

        $response->assertCreated();
        $this->assertNotNull(Payment::find($response->json('id')));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #545 (SET-UX1): the Payments section of Sales Settings edits the
 * #486 payment-method master — which methods are offered, the label, whether
 * a reference is required, and the deposit-to bank account. Each edit is
 * proven against the live readers through the real HTTP routes:
 *
 *   - is_active            → POST /payments rejects a switched-off method
 *   - requires_reference   → POST /payments needs a reference
 *   - default_bank_account_id → POST /payments/{id}/post debits that bank's GL
 */
class Issue545PaymentMethodSettingsTest extends TenantTestCase
{
    private User $admin;

    private User $salesUser;

    private Customer $customer;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = $this->makeUser('admin', UserRole::ADMIN);
        $this->salesUser = $this->makeUser('sales', UserRole::SALES);

        $this->customer = Customer::factory()->create(['payment_terms' => 30]);

        $this->seed(\Database\Seeders\PaymentMethodsSeeder::class);
    }

    private function makeUser(string $accessRole, UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->spatieName());
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

    private function postPayment(array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/payments', $overrides + [
            'invoice_id' => $this->openInvoice()->id,
            'amount' => 100,
            'method' => 'bank_transfer',
        ]);
    }

    private function method(string $code): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    private function updateMethod(string $code, array $patch, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)
            ->putJson('/api/v1/accounting/payment-methods/'.$this->method($code)->id, $patch);
    }

    public function test_switching_a_method_off_removes_it_from_payment_entry(): void
    {
        $this->postPayment(['method' => 'card'])->assertCreated();

        $this->updateMethod('card', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->postPayment(['method' => 'card'])->assertStatus(422);

        // The payment forms' list drops it; the settings list still shows it.
        $active = collect($this->actingAs($this->admin)->getJson('/api/v1/accounting/payment-methods')->assertOk()->json('data'))->pluck('code');
        $this->assertFalse($active->contains('card'));

        $all = collect($this->actingAs($this->admin)->getJson('/api/v1/accounting/payment-methods?include_inactive=1')->assertOk()->json('data'));
        $this->assertFalse((bool) $all->firstWhere('code', 'card')['is_active']);

        $this->updateMethod('card', ['is_active' => true])->assertOk();
        $this->postPayment(['method' => 'card'])->assertCreated();
    }

    public function test_requires_reference_is_enforced_on_payment_entry(): void
    {
        $this->postPayment(['method' => 'bank_transfer'])->assertCreated();

        $this->updateMethod('bank_transfer', ['requires_reference' => true])->assertOk();

        $this->postPayment(['method' => 'bank_transfer'])->assertStatus(422);
        $this->postPayment(['method' => 'bank_transfer', 'reference' => 'TRF-001'])->assertCreated();
    }

    public function test_label_is_shown_on_the_payment_method_list(): void
    {
        $this->updateMethod('bank_transfer', ['label' => 'Bank transfer (Maybank)'])->assertOk();

        $row = collect($this->actingAs($this->admin)->getJson('/api/v1/accounting/payment-methods')->json('data'))
            ->firstWhere('code', 'bank_transfer');
        $this->assertSame('Bank transfer (Maybank)', $row['label']);
    }

    public function test_deposit_to_account_drives_the_posted_debit(): void
    {
        $glId = DB::table('chart_of_accounts')->insertGetId([
            'code' => '1099',
            'name' => 'Issue 545 Deposit Bank',
            'type' => 'asset',
            'account_type' => 'asset',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bank = BankAccount::create([
            'account_name' => 'Issue 545 Deposit',
            'account_number' => '545-001',
            'bank_name' => 'Test Bank',
            'gl_account_id' => $glId,
            'is_active' => true,
        ]);

        $this->updateMethod('bank_transfer', ['default_bank_account_id' => $bank->id])
            ->assertOk()
            ->assertJsonPath('data.default_bank_account_id', $bank->id);

        $payment = Payment::findOrFail($this->postPayment(['amount' => 250])->assertCreated()->json('id'));
        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment->id}/post")->assertOk();

        $lines = JournalEntry::findOrFail($payment->fresh()->journal_entry_id)->lines()->get();
        $debit = $lines->first(fn ($l) => (float) $l->debit > 0);
        $this->assertSame($glId, (int) $debit->account_id, 'the deposit-to bank account must receive the debit');
        $this->assertEqualsWithDelta(
            0.0,
            $lines->sum(fn ($l) => (float) $l->debit) - $lines->sum(fn ($l) => (float) $l->credit),
            0.001,
            'books must balance',
        );

        // Clearing it falls back to the method's mapping again.
        $this->updateMethod('bank_transfer', ['default_bank_account_id' => null])
            ->assertOk()
            ->assertJsonPath('data.default_bank_account_id', null);
    }

    public function test_the_last_active_method_cannot_be_switched_off(): void
    {
        PaymentMethod::query()->where('code', '!=', 'cash')->update(['is_active' => false]);

        $this->updateMethod('cash', ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($this->method('cash')->fresh()->is_active);
    }

    public function test_unknown_bank_account_is_rejected(): void
    {
        $this->updateMethod('bank_transfer', ['default_bank_account_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_bank_account_id');
    }

    public function test_only_settings_writers_can_edit_the_master(): void
    {
        $this->updateMethod('card', ['is_active' => false], $this->salesUser)->assertForbidden();

        $this->assertTrue($this->method('card')->fresh()->is_active);
    }
}

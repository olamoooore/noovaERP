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
 * Issue #444 (SALES-ODOO): customer bank accounts — managed on the customer,
 * encrypted at rest, masked for viewers without the bank-account read
 * permission (Decision on #919 / #567 MasksSensitiveFields, reusing the
 * seeded contacts.bank_accounts.view literal), and usable as the payee of a
 * #528 credit-note refund.
 *
 * Accounts live on the customer's canonical Contact Master contact
 * (contact_bank_accounts, ADR-003A) and are reached only through a customer
 * inside the requester's legal-entity scope (#434).
 *
 * Everything runs through the real router → middleware → controller; refunds
 * post through the PostingEngine (never mocked).
 */
class Issue444CustomerBankAccountsTest extends TenantTestCase
{
    private const NUMBER = '5140123456789';

    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private User $admin;

    private User $accountant;

    private User $sales;

    private Customer $customer;

    private Customer $otherEntityCustomer;

    private int $arAccountId;

    private int $bankGlAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Admin', UserRole::ACCOUNTANT->spatieName(), UserRole::SALES->spatieName()] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue444 Customer Bank Accounts Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue444 Group',
            'code' => 'I444-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['A', 'B'] as $suffix) {
            $this->{'entity'.$suffix} = DB::table('legal_entities')->insertGetId([
                'group_id' => $groupId,
                'name' => "Entity {$suffix}",
                'code' => "444-{$suffix}-".Str::upper(Str::random(5)),
                'country_code' => 'MY',
                'base_currency' => 'MYR',
                'fiscal_year_start' => '01-01',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->admin = $this->makeUser('admin', 'Admin', [$this->entityA, $this->entityB]);
        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT->spatieName(), [$this->entityA]);
        $this->sales = $this->makeUser('sales', UserRole::SALES->spatieName(), [$this->entityA]);

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

        $this->customer = Customer::factory()->create(['is_active' => true, 'legal_entity_id' => $this->entityA]);
        $this->otherEntityCustomer = Customer::factory()->create(['is_active' => true, 'legal_entity_id' => $this->entityB]);
    }

    /** @param  array<int, int>  $entityIds */
    private function makeUser(string $centralRole, string $spatieRole, array $entityIds): User
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

        foreach ($entityIds as $i => $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => $centralRole,
                'is_default' => $i === 0,
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

    /** @param  array<string, mixed>  $overrides */
    private function createBankAccount(User $user, Customer $customer, array $overrides = []): TestResponse
    {
        return $this->as($user)->postJson("/api/v1/customers/{$customer->id}/bank-accounts", array_merge([
            'bank_name' => 'Maybank',
            'account_name' => 'Issue444 Customer Sdn Bhd',
            'account_number' => self::NUMBER,
            'swift_code' => 'MBBEMYKL',
            'currency' => 'MYR',
            'is_primary' => true,
        ], $overrides));
    }

    public function test_bank_accounts_are_managed_on_the_customer_and_encrypted_at_rest(): void
    {
        $created = $this->createBankAccount($this->accountant, $this->customer);
        $created->assertCreated();
        $id = (string) $created->json('id');

        // The permission holder sees the full number.
        $this->assertSame(self::NUMBER, $created->json('account_number'));

        // Stored on the customer's canonical contact, encrypted at rest.
        $contactId = $this->customer->fresh()->core_contact_uuid;
        $this->assertNotNull($contactId, 'the customer is linked to its Contact Master contact');
        $raw = DB::table('contact_bank_accounts')->where('id', $id)->first();
        $this->assertSame($contactId, $raw->contact_id);
        $this->assertNotSame(self::NUMBER, $raw->account_number, 'account number is encrypted at rest');
        $this->assertStringNotContainsString(self::NUMBER, (string) $raw->account_number);

        $list = $this->as($this->accountant)->getJson("/api/v1/customers/{$this->customer->id}/bank-accounts");
        $list->assertOk();
        $this->assertSame([$id], array_column($list->json('data'), 'id'));
        $this->assertSame(self::NUMBER, $list->json('data.0.account_number'));

        $this->as($this->accountant)
            ->putJson("/api/v1/customers/{$this->customer->id}/bank-accounts/{$id}", ['bank_name' => 'CIMB', 'account_number' => '7000111222'])
            ->assertOk()
            ->assertJsonPath('bank_name', 'CIMB')
            ->assertJsonPath('account_number', '7000111222');

        $this->as($this->accountant)
            ->deleteJson("/api/v1/customers/{$this->customer->id}/bank-accounts/{$id}")
            ->assertOk();

        $this->assertSame([], $this->as($this->accountant)->getJson("/api/v1/customers/{$this->customer->id}/bank-accounts")->json('data'));
    }

    public function test_a_customer_outside_the_users_legal_entities_is_not_reachable(): void
    {
        // The admin can add one to the entity-B customer...
        $foreign = $this->createBankAccount($this->admin, $this->otherEntityCustomer);
        $foreign->assertCreated();
        $foreignId = (string) $foreign->json('id');

        // ...but an accountant scoped to entity A cannot list, add, edit or delete there.
        $this->as($this->accountant)->getJson("/api/v1/customers/{$this->otherEntityCustomer->id}/bank-accounts")->assertNotFound();
        $this->createBankAccount($this->accountant, $this->otherEntityCustomer)->assertNotFound();
        $this->as($this->accountant)
            ->putJson("/api/v1/customers/{$this->otherEntityCustomer->id}/bank-accounts/{$foreignId}", ['bank_name' => 'X'])
            ->assertNotFound();

        // Nor reach another customer's account through an in-scope customer's URL.
        $this->as($this->accountant)
            ->putJson("/api/v1/customers/{$this->customer->id}/bank-accounts/{$foreignId}", ['bank_name' => 'X'])
            ->assertNotFound();
        $this->as($this->accountant)
            ->deleteJson("/api/v1/customers/{$this->customer->id}/bank-accounts/{$foreignId}")
            ->assertNotFound();

        $this->assertSame('Maybank', DB::table('contact_bank_accounts')->where('id', $foreignId)->value('bank_name'));
        $this->assertNull(DB::table('contact_bank_accounts')->where('id', $foreignId)->value('deleted_at'));
    }

    public function test_viewers_without_the_bank_account_permission_see_masked_numbers_and_cannot_write(): void
    {
        $this->createBankAccount($this->accountant, $this->customer, ['iban' => 'MY12MBBE5140123456789'])->assertCreated();

        // Sales holds customers.view but not contacts.bank_accounts.view.
        $list = $this->as($this->sales)->getJson("/api/v1/customers/{$this->customer->id}/bank-accounts");
        $list->assertOk();
        $this->assertStringNotContainsString(self::NUMBER, $list->getContent());
        $this->assertStringNotContainsString('MY12MBBE5140123456789', $list->getContent());
        $this->assertSame('5***********9', $list->json('data.0.account_number'));
        $this->assertSame('Maybank', $list->json('data.0.bank_name'));

        // Can't read → can't write.
        $this->createBankAccount($this->sales, $this->customer, ['account_number' => '1111'])->assertForbidden();
        $this->assertSame(1, DB::table('contact_bank_accounts')->whereNull('deleted_at')->count());
    }

    // ─── #528 refunds paid to a customer bank account ───────────────

    private function postedInvoice(float $total): Invoice
    {
        $created = $this->as($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'description' => 'Issue444 Widget',
                'quantity' => 1,
                'unit_price' => $total,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $created->assertCreated($created->getContent());

        $this->as($this->admin)->postJson("/api/v1/invoices/{$created->json('id')}/post")->assertOk();

        return Invoice::findOrFail($created->json('id'));
    }

    /** A fully paid invoice of 100 credited 60: the 60 is customer credit. */
    private function creditNoteHoldingSixty(): int
    {
        $origin = $this->postedInvoice(100);
        $this->as($this->admin)->postJson('/api/v1/payments/receipts', [
            'customer_id' => $this->customer->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'TT-444-'.Str::random(4),
            'applications' => [['invoice_id' => $origin->id, 'amount' => 100]],
        ])->assertSuccessful();

        $created = $this->as($this->admin)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $origin->customer_id,
            'invoice_id' => $origin->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'Issue444 credit',
            'lines' => [[
                'description' => 'Credit against '.$origin->invoice_number,
                'quantity' => 1,
                'unit_price' => 60,
                'tax_rate' => 0,
            ]],
        ]);
        $created->assertCreated($created->getContent());
        $id = (int) $created->json('id');

        $this->as($this->admin)->postJson("/api/v1/accounting/customers/credit-notes/{$id}/post")->assertOk();

        return $id;
    }

    private function companyBankAccount(string $currency): int
    {
        DB::table('currencies')->updateOrInsert(
            ['code' => $currency],
            ['name' => $currency, 'symbol' => $currency, 'decimal_places' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        return (int) DB::table('bank_accounts')->insertGetId([
            'account_name' => "Issue444 {$currency} account",
            'account_number' => Str::random(8),
            'bank_name' => 'Issue444 Bank',
            'currency_code' => $currency,
            'gl_account_id' => $this->bankGlAccountId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function refund(int $creditNoteId, float $amount, ?string $customerBankAccountId): TestResponse
    {
        $note = CustomerCreditNote::findOrFail($creditNoteId);
        $currency = strtoupper((string) ($note->currency_code ?: $note->base_currency_code ?: 'MYR'));

        return $this->as($this->admin)->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/refunds", [
            'amount' => $amount,
            'bank_account_id' => $this->companyBankAccount($currency),
            'customer_bank_account_id' => $customerBankAccountId,
            'method' => 'bank_transfer',
            'refund_date' => now()->toDateString(),
            'reference' => 'RF-444',
        ]);
    }

    public function test_a_refund_is_paid_to_the_selected_customer_bank_account_and_records_it(): void
    {
        $creditNoteId = $this->creditNoteHoldingSixty();
        $currency = strtoupper((string) (CustomerCreditNote::findOrFail($creditNoteId)->currency_code ?: 'MYR'));

        $payee = (string) $this->createBankAccount($this->admin, $this->customer, ['currency' => $currency])->assertCreated()->json('id');
        $foreign = (string) $this->createBankAccount($this->admin, $this->otherEntityCustomer)->assertCreated()->json('id');
        $journalsBefore = DB::table('journal_entries')->count();

        // Another customer's bank account is never a valid payee.
        $this->refund($creditNoteId, 10, $foreign)->assertStatus(422);
        $this->assertSame($journalsBefore, DB::table('journal_entries')->count(), 'no journal for the refused refund');

        $response = $this->refund($creditNoteId, 40, $payee);
        $response->assertCreated($response->getContent());
        $this->assertSame($payee, $response->json('data.customer_bank_account_id'));
        $this->assertSame(
            $payee,
            DB::table('customer_credit_note_refunds')->where('id', $response->json('data.id'))->value('customer_bank_account_id'),
            'the refund records the customer bank account it was paid to'
        );
        $this->assertSame($journalsBefore + 1, DB::table('journal_entries')->count());

        // A removed customer bank account can no longer be paid to.
        $this->as($this->admin)->deleteJson("/api/v1/customers/{$this->customer->id}/bank-accounts/{$payee}")->assertOk();
        $this->refund($creditNoteId, 10, $payee)->assertStatus(422);
        $this->assertSame($journalsBefore + 1, DB::table('journal_entries')->count());
    }
}

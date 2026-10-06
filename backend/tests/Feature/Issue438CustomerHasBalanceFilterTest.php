<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #438 (CU10) — the customer-list `has_balance` filter must match only
 * real open balances.
 *
 * It used `whereHas('invoices', status != 'paid' AND status != 'draft')`,
 * which also matched voided/reversed/cancelled and fully-credited invoices
 * (and any stale-status row), so customers who owed nothing were listed.
 *
 * The fix mirrors the definition the AR context strip and aging report use
 * (AgedReceivablesService::getCustomerContext): open statuses only, balance
 * above zero, and no superseded/voided/reversed lifecycle. Every assertion
 * runs through the real router (middleware + controller).
 */
class Issue438CustomerHasBalanceFilterTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Issue 438 Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTenantSchemaReady(['customers', 'invoices']);
    }

    private function customer(string $name, ?int $legalEntityId = null): Customer
    {
        return Customer::factory()->create([
            'name' => $name,
            'legal_entity_id' => $legalEntityId,
        ]);
    }

    private function invoiceFor(Customer $customer, array $attributes = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'currency_code' => null,
            'exchange_rate' => 1,
            'superseded_at' => null,
        ], $attributes));
    }

    /**
     * Customer ids returned by GET /api/v1/customers?has_balance=1.
     *
     * @return int[]
     */
    private function hasBalanceIds(?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->admin, 'sanctum')
            ->getJson('/api/v1/customers?has_balance=1&per_page=100')
            ->assertOk();

        return collect($response->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ── Documents that must NOT put a customer on the list ────────────────

    public function test_fully_paid_invoice_only_is_excluded(): void
    {
        $customer = $this->customer('Fully Paid');

        $this->invoiceFor($customer, [
            'status' => 'paid',
            'lifecycle_status' => 'posted',
            'settlement_status' => 'paid',
            'total' => 500,
            'paid_amount' => 500,
            'balance' => 0,
        ]);

        $this->assertNotContains(
            (int) $customer->id,
            $this->hasBalanceIds(),
            'A customer whose only invoice is fully paid owes nothing and must be excluded.'
        );
    }

    public function test_voided_invoice_only_is_excluded(): void
    {
        $customer = $this->customer('Voided Only');

        // DocumentLifecycleService::markVoided keeps the operational status and
        // sets the lifecycle dimension — the row still carries its old balance.
        $this->invoiceFor($customer, [
            'status' => 'sent',
            'lifecycle_status' => 'voided',
            'voided_at' => now(),
            'is_active_version' => false,
            'total' => 400,
            'paid_amount' => 0,
            'balance' => 400,
        ]);

        $this->assertNotContains(
            (int) $customer->id,
            $this->hasBalanceIds(),
            'A voided invoice is not an open balance.'
        );
    }

    public function test_reversed_invoice_only_is_excluded(): void
    {
        $customer = $this->customer('Reversed Only');

        $this->invoiceFor($customer, [
            'status' => 'sent',
            'lifecycle_status' => 'reversed',
            'reversed_at' => now(),
            'is_active_version' => false,
            'total' => 400,
            'paid_amount' => 0,
            'balance' => 400,
        ]);

        $this->assertNotContains(
            (int) $customer->id,
            $this->hasBalanceIds(),
            'A reversed invoice is not an open balance.'
        );
    }

    public function test_superseded_invoice_only_is_excluded(): void
    {
        $customer = $this->customer('Superseded Only');

        $this->invoiceFor($customer, [
            'status' => 'sent',
            'lifecycle_status' => 'superseded',
            'superseded_at' => now(),
            'is_active_version' => false,
            'total' => 400,
            'paid_amount' => 0,
            'balance' => 400,
        ]);

        $this->assertNotContains(
            (int) $customer->id,
            $this->hasBalanceIds(),
            'A superseded invoice is not an open balance.'
        );
    }

    public function test_cancelled_invoice_only_is_excluded(): void
    {
        $customer = $this->customer('Cancelled Only');

        $this->invoiceFor($customer, [
            'status' => 'cancelled',
            'lifecycle_status' => 'cancelled',
            'total' => 400,
            'paid_amount' => 0,
            'balance' => 400,
        ]);

        $this->assertNotContains(
            (int) $customer->id,
            $this->hasBalanceIds(),
            'A cancelled invoice is not an open balance.'
        );
    }

    public function test_fully_credited_invoice_only_is_excluded(): void
    {
        $customer = $this->customer('Fully Credited');

        // Issue #510 contract: balance = max(0, total − paid − credited); a
        // fully credited invoice keeps its operational status but owes 0.
        $this->invoiceFor($customer, [
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'total' => 300,
            'paid_amount' => 0,
            'credited_amount' => 300,
            'balance' => 0,
        ]);

        $this->assertNotContains(
            (int) $customer->id,
            $this->hasBalanceIds(),
            'A fully credited invoice is settled and must be excluded.'
        );
    }

    public function test_draft_invoice_only_is_excluded(): void
    {
        $customer = $this->customer('Draft Only');

        $this->invoiceFor($customer, [
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'total' => 250,
            'paid_amount' => 0,
            'balance' => 250,
        ]);

        $this->assertNotContains(
            (int) $customer->id,
            $this->hasBalanceIds(),
            'A draft invoice is not a receivable yet.'
        );
    }

    // ── Documents that MUST put a customer on the list ────────────────────

    public function test_posted_invoice_with_an_open_balance_is_included(): void
    {
        $customer = $this->customer('Posted Open');

        $this->invoiceFor($customer, [
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'total' => 800,
            'paid_amount' => 0,
            'balance' => 800,
        ]);

        $this->assertContains(
            (int) $customer->id,
            $this->hasBalanceIds(),
            'A posted invoice with an unpaid balance is an open receivable.'
        );
    }

    public function test_partially_paid_invoice_with_a_remaining_balance_is_included(): void
    {
        $customer = $this->customer('Partial Open');

        $this->invoiceFor($customer, [
            'status' => 'partial',
            'lifecycle_status' => 'posted',
            'settlement_status' => 'partially_paid',
            'total' => 1000,
            'paid_amount' => 400,
            'balance' => 600,
        ]);

        $this->assertContains(
            (int) $customer->id,
            $this->hasBalanceIds(),
            'A partially paid invoice still has an open balance.'
        );
    }

    // ── Mixed document sets ───────────────────────────────────────────────

    public function test_mixed_documents_list_only_the_customer_that_still_owes(): void
    {
        $open = $this->customer('Mixed With Open Balance');
        $settled = $this->customer('Mixed All Settled');

        foreach ([$open, $settled] as $customer) {
            $this->invoiceFor($customer, [
                'status' => 'paid',
                'lifecycle_status' => 'posted',
                'total' => 100,
                'paid_amount' => 100,
                'balance' => 0,
            ]);
            $this->invoiceFor($customer, [
                'status' => 'sent',
                'lifecycle_status' => 'voided',
                'voided_at' => now(),
                'total' => 100,
                'paid_amount' => 0,
                'balance' => 100,
            ]);
            $this->invoiceFor($customer, [
                'status' => 'sent',
                'lifecycle_status' => 'posted',
                'total' => 100,
                'paid_amount' => 0,
                'credited_amount' => 100,
                'balance' => 0,
            ]);
        }

        // Only the first customer keeps one real open document.
        $this->invoiceFor($open, [
            'status' => 'partial',
            'lifecycle_status' => 'posted',
            'total' => 500,
            'paid_amount' => 200,
            'balance' => 300,
        ]);

        $ids = $this->hasBalanceIds();

        $this->assertContains((int) $open->id, $ids, 'The customer with a real open balance must be listed.');
        $this->assertNotContains((int) $settled->id, $ids, 'A customer with only settled/voided documents must not be listed.');
    }

    /**
     * The filter and the AR context strip must never disagree: the list's
     * has_balance set is exactly the customers whose /context outstanding > 0.
     */
    public function test_filter_agrees_with_the_ar_context_outstanding(): void
    {
        $customers = [];

        $customers[] = $this->customer('Agree Open');
        $this->invoiceFor($customers[0], [
            'status' => 'overdue',
            'lifecycle_status' => 'posted',
            'invoice_date' => now()->subDays(40)->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
            'total' => 750,
            'paid_amount' => 0,
            'balance' => 750,
        ]);

        $customers[] = $this->customer('Agree Partial');
        $this->invoiceFor($customers[1], [
            'status' => 'partial',
            'lifecycle_status' => 'posted',
            'total' => 400,
            'paid_amount' => 150,
            'balance' => 250,
        ]);

        $customers[] = $this->customer('Agree Paid');
        $this->invoiceFor($customers[2], [
            'status' => 'paid',
            'lifecycle_status' => 'posted',
            'total' => 400,
            'paid_amount' => 400,
            'balance' => 0,
        ]);

        $customers[] = $this->customer('Agree Voided');
        $this->invoiceFor($customers[3], [
            'status' => 'sent',
            'lifecycle_status' => 'voided',
            'voided_at' => now(),
            'total' => 900,
            'paid_amount' => 0,
            'balance' => 900,
        ]);

        $customers[] = $this->customer('Agree Cancelled');
        $this->invoiceFor($customers[4], [
            'status' => 'cancelled',
            'lifecycle_status' => 'cancelled',
            'total' => 900,
            'paid_amount' => 0,
            'balance' => 900,
        ]);

        $customers[] = $this->customer('Agree Credited');
        $this->invoiceFor($customers[5], [
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'total' => 600,
            'paid_amount' => 0,
            'credited_amount' => 600,
            'balance' => 0,
        ]);

        $expected = [];
        foreach ($customers as $customer) {
            $context = $this->actingAs($this->admin, 'sanctum')
                ->getJson("/api/v1/customers/{$customer->id}/context")
                ->assertOk();

            if ((float) $context->json('outstanding') > 0) {
                $expected[] = (int) $customer->id;
            }
        }

        $actual = $this->hasBalanceIds();

        sort($expected);
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            'has_balance must return exactly the customers the AR context reports as outstanding.'
        );
        $this->assertCount(2, $actual, 'Only the posted-overdue and partially-paid customers are outstanding.');
    }

    // ── Entity scoping interaction (#434) ─────────────────────────────────

    public function test_has_balance_still_respects_entity_scoping(): void
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 438 Group',
            'code' => 'I438-'.Str::upper(Str::random(4)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entityA = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Issue 438 Entity A',
            'code' => 'I438A-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ])->id;

        $entityB = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Issue 438 Entity B',
            'code' => 'I438B-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ])->id;

        $inScope = $this->customer('Scoped Open A', $entityA);
        $outOfScope = $this->customer('Scoped Open B', $entityB);
        $shared = $this->customer('Scoped Open Shared', null);

        foreach ([
            [$inScope, $entityA],
            [$outOfScope, $entityB],
            [$shared, $entityA],
        ] as [$customer, $entityId]) {
            $this->invoiceFor($customer, [
                'legal_entity_id' => $entityId,
                'status' => 'posted',
                'lifecycle_status' => 'posted',
                'total' => 500,
                'paid_amount' => 0,
                'balance' => 500,
            ]);
        }

        $manager = $this->makeScopedManager($entityA);

        $ids = $this->hasBalanceIds($manager);

        $this->assertContains((int) $inScope->id, $ids, 'In-scope customer with an open balance must be listed.');
        $this->assertContains((int) $shared->id, $ids, 'Shared customer with an open balance must stay visible.');
        $this->assertNotContains((int) $outOfScope->id, $ids, 'Another entity\'s customer must not leak into the filtered list.');
    }

    private function makeScopedManager(int $entityId): User
    {
        $user = User::factory()->create();

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            [
                'name' => $user->name,
                'password' => $user->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantUuid],
            [
                'role' => 'manager',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $user->assignRole(UserRole::MANAGER->spatieName());

        DB::table('user_entity_access')->insert([
            'user_id' => $user->id,
            'legal_entity_id' => $entityId,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}

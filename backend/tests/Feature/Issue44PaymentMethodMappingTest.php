<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Services\PaymentPostingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue #44 — PaymentPostingService maps payment method
 * to the correct GL account (cash, bank, card_clearing, cheque_clearing,
 * gateway_receivable), and rejects unsupported methods with a canonical
 * reason rather than silently defaulting to cash.
 */
class Issue44PaymentMethodMappingTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();

        // FK target required for payment FK to public.tenants via payment_executions
        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Test Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContextWithPermissions($this->tenantUuid);

        // Create a real user so payments.received_by FK is satisfied
        $this->user = \App\Models\User::factory()->create();
        Auth::login($this->user);
    }

    private \App\Models\User $user;

    private ?int $legalEntityId = null;

    /**
     * Seed a legal entity + a chart-of-accounts account + an
     * entity_account_roles row mapping the given account role (e.g.
     * CARD_CLEARING) to that account, so AccountRoleResolver can resolve
     * the payment-method clearing key to a concrete GL account in the
     * test tenant. Idempotent per role code.
     */
    private function seedClearingRoleMapping(string $roleCode, string $acctCode): int
    {
        $this->ensureLegalEntity();

        $roleId = DB::table('account_roles')->where('code', $roleCode)->value('id');
        $this->assertNotNull($roleId, "Account role {$roleCode} must exist (migration add_payment_method_clearing_roles)");

        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'code' => $acctCode,
            'name' => str_replace('_', ' ', $roleCode),
            'type' => 'asset',
            'account_type' => 'asset',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('entity_account_roles')->insert([
            'legal_entity_id' => $this->legalEntityId,
            'account_role_id' => $roleId,
            'chart_of_account_id' => $coaId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $coaId;
    }

    private function ensureLegalEntity(): void
    {
        if ($this->legalEntityId) {
            return;
        }

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Test Group',
            'code' => 'TGR-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->legalEntityId = (int) DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Test Entity',
            'code' => 'TENT-'.uniqid(),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makePayment(string $method): Payment
    {
        $customer = Customer::factory()->create();

        return Payment::create([
            'payment_number' => 'PAY-' . strtoupper($method) . '-' . uniqid(),
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'currency' => 'USD',
            'method' => $method,
            'payment_date' => now()->toDateString(),
            'payment_status' => 'draft',
            'received_by' => $this->user->id,
        ]);
    }

    private function drAccountId(Payment $payment): ?int
    {
        if (! $payment->journal_entry_id) {
            return null;
        }

        // The DR line is the debit side (not the AR credit line)
        $line = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $payment->journal_entry_id)
            ->where('debit', '>', 0)
            ->first();

        return $line ? (int) $line->account_id : null;
    }

    /**
     * AC1: Card payment posts to a card_clearing account, not cash.
     */
    public function test_card_payment_uses_card_clearing_account(): void
    {
        $coaId = $this->seedClearingRoleMapping('CARD_CLEARING', 'CARDCLR-'.uniqid());

        $payment = $this->makePayment('card');
        $service = app(PaymentPostingService::class);
        $posted = $service->post($payment);

        $this->assertSame('posted', $posted->payment_status);
        $this->assertNotNull($posted->journal_entry_id);

        $drAccountId = $this->drAccountId($posted->fresh());
        $this->assertSame(
            $coaId,
            $drAccountId,
            'Card payment must post to the CARD_CLEARING account, not cash'
        );
    }

    /**
     * AC2: Cheque payment posts to a cheque_clearing account, not cash.
     */
    public function test_check_payment_uses_cheque_clearing_account(): void
    {
        $coaId = $this->seedClearingRoleMapping('CHEQUE_CLEARING', 'CHKCLR-'.uniqid());

        $payment = $this->makePayment('check');
        $service = app(PaymentPostingService::class);
        $posted = $service->post($payment);

        $this->assertSame('posted', $posted->payment_status);

        $drAccountId = $this->drAccountId($posted->fresh());
        $this->assertSame(
            $coaId,
            $drAccountId,
            'Cheque payment must post to the CHEQUE_CLEARING account, not cash'
        );
    }

    /**
     * AC3: A method the policy mapping does not cover is rejected with a
     * canonical reason rather than silently posted to cash. 'other' is
     * permitted by the payments.method CHECK constraint (so it can be
     * persisted) but has no defined clearing account in the #44 policy, so
     * the service must reject it explicitly instead of defaulting to cash.
     */
    public function test_unsupported_payment_method_is_rejected(): void
    {
        $payment = $this->makePayment('other'); // DB-allowed, but not in policy mapping

        $service = app(PaymentPostingService::class);

        $threw = false;
        try {
            $service->post($payment);
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('Unsupported payment method', $e->getMessage());
            $this->assertStringContainsString('other', $e->getMessage());
        }

        $this->assertTrue($threw, 'Unsupported payment method must throw, not silently default to cash');

        // Payment status must remain 'draft' — no silent partial post
        $payment->refresh();
        $this->assertSame('draft', $payment->payment_status);
        $this->assertNull($payment->journal_entry_id);
    }

    /**
     * AC3b: An entirely-unknown method cannot even be persisted (defense in
     * depth via the DB CHECK constraint), so it can never reach the posting
     * path at all.
     */
    public function test_completely_unknown_method_cannot_be_persisted(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->makePayment('cryptocurrency');
    }

    /**
     * AC4 (regression): Bank-transfer and cash resolve to DISTINCT accounts
     * (not silently collapsed to the same cash default).
     */
    public function test_bank_and_cash_resolve_to_distinct_accounts(): void
    {
        $cashPayment = $this->makePayment('cash');
        $bankPayment = $this->makePayment('bank_transfer');

        $service = app(PaymentPostingService::class);

        $cashPosted = $service->post($cashPayment);
        $bankPosted = $service->post($bankPayment);

        $this->assertSame('posted', $cashPosted->payment_status);
        $this->assertSame('posted', $bankPosted->payment_status);

        $cashDr = $this->drAccountId($cashPosted->fresh());
        $bankDr = $this->drAccountId($bankPosted->fresh());

        $this->assertNotNull($cashDr, 'Cash DR line must resolve to an account');
        $this->assertNotNull($bankDr, 'Bank DR line must resolve to an account');
        $this->assertNotSame(
            $cashDr,
            $bankDr,
            'Bank transfer must NOT collapse to the cash account (the #44 bug)'
        );
    }

    /**
     * AC4: Gateway payment uses a distinct gateway_receivable account, not cash.
     */
    public function test_gateway_payment_uses_gateway_receivable_account(): void
    {
        $coaId = $this->seedClearingRoleMapping('GATEWAY_RECEIVABLE', 'GWYRCV-'.uniqid());

        $payment = $this->makePayment('gateway');
        $service = app(PaymentPostingService::class);
        $posted = $service->post($payment);

        $this->assertSame('posted', $posted->payment_status);

        $drAccountId = $this->drAccountId($posted->fresh());
        $this->assertSame(
            $coaId,
            $drAccountId,
            'Gateway payment must post to the GATEWAY_RECEIVABLE account'
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Services\CreditCheckService;
use Tests\TenantTestCase;

/**
 * #429 (CU1): a credit limit of 0 means cash-only and payment terms of 0 mean
 * COD. The list-page dialog sent both through truthiness checks, turning 0 into
 * null (unlimited credit under #144) and 30 days. This pins the API/DB
 * contract the fixed payload now targets: zero is stored as zero, and zero
 * credit blocks an order while an explicitly cleared limit stays unlimited.
 */
class CustomerZeroCreditLimitTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) \Illuminate\Support\Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->user = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_zero_credit_limit_and_zero_terms_are_stored_as_zero(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/customers', [
            'name' => 'COD Customer',
            'type' => 'company',
            'credit_limit' => 0,
            'payment_terms' => 0,
        ]);

        $response->assertCreated();

        $customer = Customer::findOrFail($response->json('id') ?? $response->json('data.id'));

        $this->assertEqualsWithDelta(0.0, (float) $customer->credit_limit, 0.01, 'limit 0 must stay 0, not null');
        $this->assertSame(0, (int) $customer->payment_terms, 'terms 0 (COD) must stay 0, not reset to 30');
    }

    public function test_zero_credit_limit_blocks_an_order_while_an_unlimited_customer_passes(): void
    {
        $cod = Customer::factory()->create(['credit_limit' => 0, 'total_outstanding' => 0]);
        $unlimited = Customer::factory()->create(['credit_limit' => null, 'total_outstanding' => 0]);

        $service = app(CreditCheckService::class);

        $blocked = $service->checkCreditLimit($cod, 100.0);
        $this->assertFalse($blocked->approved, 'a 0 limit is cash-only and must block credit sales');
        $this->assertStringContainsString('prepayment', strtolower($blocked->reason));

        $allowed = $service->checkCreditLimit($unlimited, 100.0);
        $this->assertTrue($allowed->approved, 'a null limit is explicitly unlimited');
    }
}

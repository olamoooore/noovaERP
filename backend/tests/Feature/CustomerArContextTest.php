<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * D2 (Oct-2026 invoice review): the customer context strip on document entry
 * screens reads GET /customers/{id}/context. It must report outstanding and
 * overdue using the same scoping rules as the AR aging report (unpaid,
 * non-superseded, purchase-status invoices only) plus credit usage and the
 * last invoice date — through the real route/middleware chain.
 */
class CustomerArContextTest extends TenantTestCase
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
            'name' => 'AR Context Tenant',
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
    }

    private function invoiceFor(Customer $customer, array $attributes): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'currency_code' => null,
            'exchange_rate' => 1,
            'superseded_at' => null,
        ], $attributes));
    }

    public function test_context_reports_outstanding_overdue_credit_and_last_invoice(): void
    {
        $customer = Customer::factory()->create([
            'legal_entity_id' => null,
            'credit_limit' => 1000,
        ]);

        // 300 overdue by 45 days, 200 not yet due — counted in outstanding.
        $this->invoiceFor($customer, [
            'status' => 'sent',
            'balance' => 300,
            'total' => 300,
            'invoice_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(45)->toDateString(),
        ]);
        $this->invoiceFor($customer, [
            'status' => 'posted',
            'balance' => 200,
            'total' => 200,
            'invoice_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        // Paid + superseded rows must not affect outstanding/overdue, but the
        // paid one is the newest invoice date.
        $this->invoiceFor($customer, [
            'status' => 'paid',
            'balance' => 0,
            'total' => 500,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $this->invoiceFor($customer, [
            'status' => 'sent',
            'lifecycle_status' => 'superseded',
            'balance' => 999,
            'total' => 999,
            'invoice_date' => now()->subDays(2)->toDateString(),
            'due_date' => now()->subDays(1)->toDateString(),
            'superseded_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/customers/{$customer->id}/context")
            ->assertOk();

        $response->assertJson([
            'customer_id' => $customer->id,
            'outstanding' => 500.0,
            'current' => 200.0,
            'overdue' => 300.0,
            'overdue_count' => 1,
            'oldest_overdue_days' => 45,
            'credit_limit' => 1000.0,
            'credit_utilization_pct' => 50.0,
            'credit_available' => 500.0,
            'last_invoice_date' => now()->toDateString(),
        ]);
    }

    public function test_context_without_credit_limit_or_invoices_stays_clean(): void
    {
        $customer = Customer::factory()->create([
            'legal_entity_id' => null,
            'credit_limit' => null,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/customers/{$customer->id}/context")
            ->assertOk()
            ->assertJson([
                'outstanding' => 0.0,
                'overdue' => 0.0,
                'overdue_count' => 0,
                'credit_limit' => null,
                'credit_utilization_pct' => null,
                'credit_available' => null,
                'last_invoice_date' => null,
            ]);
    }
}

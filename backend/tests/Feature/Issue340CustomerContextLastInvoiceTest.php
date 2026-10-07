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
 * Issue #340: the customer context strip (now on quotation and sales-order
 * forms too) links the customer's last invoice, so GET /customers/{id}/context
 * also returns that invoice's id and number — the same invoice whose date is
 * reported as last_invoice_date (purchase statuses, not superseded).
 */
class Issue340CustomerContextLastInvoiceTest extends TenantTestCase
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
            'name' => 'Last Invoice Tenant',
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

    public function test_context_returns_the_last_invoice_id_and_number(): void
    {
        $customer = Customer::factory()->create(['legal_entity_id' => null]);

        $this->invoiceFor($customer, [
            'invoice_number' => 'INV-340-OLD',
            'status' => 'paid',
            'balance' => 0,
            'total' => 100,
            'invoice_date' => now()->subDays(30)->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
        ]);
        $latest = $this->invoiceFor($customer, [
            'invoice_number' => 'INV-340-NEW',
            'status' => 'sent',
            'balance' => 50,
            'total' => 50,
            'invoice_date' => now()->subDays(3)->toDateString(),
            'due_date' => now()->addDays(27)->toDateString(),
        ]);
        // Newer, but a draft (not a purchase) and a superseded row: ignored.
        $this->invoiceFor($customer, [
            'invoice_number' => 'INV-340-DRAFT',
            'status' => 'draft',
            'balance' => 70,
            'total' => 70,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $this->invoiceFor($customer, [
            'invoice_number' => 'INV-340-SUPERSEDED',
            'status' => 'sent',
            'lifecycle_status' => 'superseded',
            'balance' => 80,
            'total' => 80,
            'invoice_date' => now()->subDay()->toDateString(),
            'due_date' => now()->addDays(29)->toDateString(),
            'superseded_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/customers/{$customer->id}/context")
            ->assertOk()
            ->assertJson([
                'last_invoice_date' => now()->subDays(3)->toDateString(),
                'last_invoice_id' => $latest->id,
                'last_invoice_number' => 'INV-340-NEW',
            ]);
    }

    public function test_context_without_invoices_returns_null_last_invoice(): void
    {
        $customer = Customer::factory()->create(['legal_entity_id' => null]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/customers/{$customer->id}/context")
            ->assertOk();

        $this->assertArrayHasKey('last_invoice_id', $response->json());
        $this->assertNull($response->json('last_invoice_id'));
        $this->assertArrayHasKey('last_invoice_number', $response->json());
        $this->assertNull($response->json('last_invoice_number'));
    }
}

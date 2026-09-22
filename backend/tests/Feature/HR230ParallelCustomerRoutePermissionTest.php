<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #230 (CRITICAL) — the parallel /accounting/customers route block
 * (CustomersController index/show) had no permission gate: any authenticated
 * user could read tenant-wide customer PII. #125 gated the primary
 * CustomerController only.
 *
 * Verified through the real router: cashier (no customers.view) is denied,
 * viewer (customers.view) is allowed.
 */
class HR230ParallelCustomerRoutePermissionTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR230 Tenant', 'email' => 'hr230-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function actingAsRole(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);

        return $user;
    }

    public function test_cashier_cannot_list_accounting_customers(): void
    {
        $user = $this->actingAsRole('cashier', 'Cashier');

        $response = $this->actingAs($user)->getJson('/api/v1/accounting/customers');

        $this->assertSame(403, $response->getStatusCode(),
            'Cashier holds no customers.view and must not read tenant-wide customer PII');
    }

    public function test_cashier_cannot_show_accounting_customer(): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'HR230 Customer', 'email' => 'hr230-cust@example.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = $this->actingAsRole('cashier', 'Cashier');

        $response = $this->actingAs($user)->getJson("/api/v1/accounting/customers/{$customerId}");

        $this->assertSame(403, $response->getStatusCode(),
            'Cashier must not read a single customer record either');
    }

    public function test_viewer_can_list_accounting_customers(): void
    {
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->getJson('/api/v1/accounting/customers');

        $this->assertSame(200, $response->getStatusCode(),
            'Viewer has customers.view: '.$response->getContent());
    }

    public function test_viewer_can_show_accounting_customer(): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'HR230 Customer 2', 'email' => 'hr230-cust2@example.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->getJson("/api/v1/accounting/customers/{$customerId}");

        $this->assertSame(200, $response->getStatusCode(),
            'Viewer has customers.view: '.$response->getContent());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * The Sales dashboard calls the sales analytics endpoints and the per-user
 * pinned widgets. Those must be readable with reports.view OR sales.view so
 * the Sales role can load its dashboard, while the financial reports
 * (P&L, balance sheet, GL) stay reports.view-only.
 */
class SalesDashboardAccessTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    private function centralUser(string $role): User
    {
        $user = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->assignRole(UserRole::from($role)->spatieName());

        return $user;
    }

    /**
     * @return string[]
     */
    private function salesCockpitEndpoints(): array
    {
        $period = '?start_date=2026-10-01&end_date=2026-10-31';

        return [
            '/api/v1/analytics/sales/by-product'.$period,
            '/api/v1/analytics/sales/trends'.$period.'&interval=day',
            '/api/v1/analytics/sales/by-customer'.$period,
            '/api/v1/analytics/sales/by-category'.$period,
            '/api/v1/reports/dashboard/widgets',
        ];
    }

    public function test_sales_role_can_load_the_sales_dashboard_but_not_financial_reports(): void
    {
        $sales = $this->centralUser('sales');

        foreach ($this->salesCockpitEndpoints() as $path) {
            $this->actingAs($sales)->getJson($path)->assertOk();
        }

        // The carve-out must not widen Sales into the financial reports.
        $this->actingAs($sales)
            ->getJson('/api/v1/reports/financial/profit-loss')
            ->assertForbidden();
        $this->actingAs($sales)
            ->getJson('/api/v1/reports/financial/balance-sheet')
            ->assertForbidden();
    }

    public function test_reports_view_roles_keep_access_to_both(): void
    {
        $admin = $this->centralUser('admin');

        foreach ($this->salesCockpitEndpoints() as $path) {
            $this->actingAs($admin)->getJson($path)->assertOk();
        }

        $this->actingAs($admin)
            ->getJson('/api/v1/reports/financial/profit-loss?start_date=2026-10-01&end_date=2026-10-31')
            ->assertOk();
    }
}

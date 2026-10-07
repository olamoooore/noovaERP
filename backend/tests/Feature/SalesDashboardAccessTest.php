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
 * Issue #356 — product-owner decision 2026-10-07: "Revert, keep blocked".
 * The Sales role must NOT have access to sales analytics: /analytics/sales/*
 * and the reports dashboard widgets stay behind reports.view, which SALES does
 * not hold (no RolePermissionSeeder change). The Sales cockpit's operational
 * action queues (#365) are document work lists, not analytics, and stay
 * readable with sales.view. Every assertion goes through the real router.
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
     * Sales analytics / report figures the Sales dashboard reads.
     *
     * @return string[]
     */
    private function salesAnalyticsEndpoints(): array
    {
        $period = '?start_date=2026-10-01&end_date=2026-10-31';

        return [
            '/api/v1/analytics/sales/by-product'.$period,
            '/api/v1/analytics/sales/summary'.$period,
            '/api/v1/analytics/sales/trends'.$period.'&interval=day',
            '/api/v1/analytics/sales/by-customer'.$period,
            '/api/v1/analytics/sales/by-category'.$period,
            '/api/v1/analytics/sales/credit-notes'.$period.'&group_by=reason',
            '/api/v1/analytics/sales/drilldown'.$period.'&dimension=product&key=none',
            '/api/v1/reports/sales-records'.$period,
            '/api/v1/reports/dashboard/widgets',
        ];
    }

    public function test_sales_role_is_denied_sales_analytics_and_financial_reports(): void
    {
        $sales = $this->centralUser('sales');

        foreach ($this->salesAnalyticsEndpoints() as $path) {
            $response = $this->actingAs($sales)->getJson($path);
            $response->assertStatus(403, "Sales must be denied {$path}");
            $response->assertJsonPath('required_permission', 'reports.view');
        }

        $this->actingAs($sales)
            ->getJson('/api/v1/reports/financial/profit-loss')
            ->assertForbidden();
    }

    public function test_reports_view_roles_can_read_sales_analytics(): void
    {
        // MANAGER and VIEWER hold reports.view in the seeder (not super roles).
        foreach (['manager', 'viewer'] as $role) {
            $user = $this->centralUser($role);
            foreach ([
                '/api/v1/analytics/sales/by-product?start_date=2026-10-01&end_date=2026-10-31',
                '/api/v1/analytics/sales/trends?start_date=2026-10-01&end_date=2026-10-31&interval=day',
                '/api/v1/analytics/sales/by-customer?start_date=2026-10-01&end_date=2026-10-31',
                '/api/v1/analytics/sales/by-category?start_date=2026-10-01&end_date=2026-10-31',
                '/api/v1/reports/dashboard/widgets',
            ] as $path) {
                $response = $this->actingAs($user)->getJson($path);
                $response->assertStatus(200, "{$role} must read {$path}: ".$response->getContent());
            }
        }
    }

    public function test_sales_role_keeps_its_operational_action_queues(): void
    {
        $sales = $this->centralUser('sales');

        $response = $this->actingAs($sales)->getJson('/api/v1/sales/dashboard/action-queues');
        $response->assertStatus(200, $response->getContent());
    }
}

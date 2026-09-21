<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #103 (HIGH) — the FinancialReporting module routes were protected only
 * by tenant/entity middleware: any authenticated tenant user could pull P&L,
 * Balance Sheet, Trial Balance and General Ledger, and mutate report
 * schedules/cache/templates.
 *
 * Real HTTP through the router.
 */
class HR103FinancialReportingPermissionTest extends TenantTestCase
{
    protected User $viewer;

    protected User $salesUser;

    protected User $accountant;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Viewer', 'Sales', 'Accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Reporting RBAC Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('Viewer');

        // Sales has neither reports.view nor reports.manage.
        $this->salesUser = User::factory()->create();
        $this->salesUser->assignRole('Sales');

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('Accountant');

        foreach ([
            ['user' => $this->viewer, 'role' => 'viewer'],
            ['user' => $this->salesUser, 'role' => 'sales'],
            ['user' => $this->accountant, 'role' => 'accountant'],
        ] as $entry) {
            DB::table('user_company_access')->insert([
                'user_id' => $entry['user']->id,
                'tenant_id' => $this->tenantId,
                'role' => $entry['role'],
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function periodQuery(): string
    {
        return '?start_date='.now()->startOfMonth()->toDateString().'&end_date='.now()->toDateString();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reads require reports.view
    // ─────────────────────────────────────────────────────────────────────

    public function test_sales_user_cannot_read_financial_statements(): void
    {
        foreach (['profit-loss', 'balance-sheet', 'trial-balance', 'cash-flow', 'general-ledger'] as $report) {
            $response = $this->actingAs($this->salesUser)
                ->getJson("/api/v1/reports/financial/{$report}{$this->periodQuery()}");

            $response->assertStatus(403, "Sales must be denied {$report}");
            $response->assertJsonPath('required_permission', 'reports.view');
        }
    }

    public function test_viewer_can_read_financial_statements(): void
    {
        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/financial/profit-loss'.$this->periodQuery());

        $response->assertStatus(200, $response->getContent());
    }

    public function test_sales_user_cannot_read_inventory_reports_or_analytics(): void
    {
        $this->actingAs($this->salesUser)
            ->getJson('/api/v1/reports/inventory/valuation')
            ->assertStatus(403);

        $this->actingAs($this->salesUser)
            ->getJson('/api/v1/analytics/sales/by-customer')
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Mutations require reports.manage (reports.view alone is not enough)
    // ─────────────────────────────────────────────────────────────────────

    public function test_viewer_cannot_mutate_reporting_configuration(): void
    {
        $targets = [
            ['post', '/api/v1/reports/export/csv'],
            ['post', '/api/v1/reports/schedules'],
            ['post', '/api/v1/reports/cache/clear-all'],
            ['post', '/api/v1/reports/templates'],
        ];

        foreach ($targets as [$verb, $uri]) {
            $response = $this->actingAs($this->viewer)->{$verb.'Json'}($uri);

            $response->assertStatus(403, "Viewer must be denied {$verb} {$uri}");
            $response->assertJsonPath('required_permission', 'reports.manage');
        }
    }

    public function test_accountant_passes_the_reporting_manage_gate(): void
    {
        $response = $this->actingAs($this->accountant)
            ->postJson('/api/v1/reports/cache/clear-expired');

        $this->assertNotSame(403, $response->getStatusCode(), 'Accountant must hold reports.manage: '.$response->getContent());
    }

    public function test_viewer_can_read_reporting_configuration_lists(): void
    {
        $this->actingAs($this->viewer)->getJson('/api/v1/reports/schedules')->assertStatus(200);
        $this->actingAs($this->viewer)->getJson('/api/v1/reports/templates')->assertStatus(200);
        $this->actingAs($this->viewer)->getJson('/api/v1/reports/cache/stats')->assertStatus(200);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/reports/financial/profit-loss'.$this->periodQuery())->assertStatus(401);
    }
}

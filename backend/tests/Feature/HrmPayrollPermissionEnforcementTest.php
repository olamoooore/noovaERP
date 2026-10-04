<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\HRM\Models\Contract;
use Modules\HRM\Models\Employee;
use Modules\HRM\Models\PayrollRun;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #787 (Y1): the whole HRM group carried only auth + entitlement:hrm, so any
 * entitled user (VIEWER, CASHIER) could read every salary, approve a payroll
 * run and mark it paid. Every route now declares a RequirePermission and the
 * salary-bearing resource hides pay without hrm.payroll.view.
 *
 * Router-level: postJson/getJson through middleware -> controller.
 */
class HrmPayrollPermissionEnforcementTest extends TenantTestCase
{
    private User $viewer;

    private User $manager;

    private User $admin;

    private string $tenantId;

    private Employee $employee;

    private Contract $contract;

    private PayrollRun $payrollRun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Viewer', 'Manager', 'Admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'HRM Payroll RBAC Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });

        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('Viewer');

        $this->manager = User::factory()->create();
        $this->manager->assignRole(UserRole::MANAGER->spatieName());

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());

        foreach ([
            ['user' => $this->viewer, 'role' => 'viewer'],
            ['user' => $this->manager, 'role' => 'manager'],
            ['user' => $this->admin, 'role' => 'admin'],
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

        $this->employee = Employee::create([
            'employee_number' => 'EMP-'.Str::upper(Str::random(6)),
            'name' => 'Salaried Person',
            'email' => 'emp-'.uniqid().'@hrm.test',
            'status' => 'active',
        ]);

        $this->contract = Contract::create([
            'employee_id' => $this->employee->id,
            'job_title' => 'Engineer',
            'status' => 'active',
            'base_monthly_salary' => 5000,
        ]);

        // Created by the viewer so the approving user is never the creator —
        // the assertions must fail on the PERMISSION, not the SoD rule.
        $this->payrollRun = PayrollRun::create([
            'reference' => 'PR-'.Str::upper(Str::random(6)),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'calculated',
            'total_gross' => 5000,
            'total_deductions' => 500,
            'total_net' => 4500,
            'currency' => 'MYR',
            'created_by' => $this->viewer->id,
        ]);
    }

    public function test_viewer_cannot_read_payroll_runs(): void
    {
        $response = $this->actingAs($this->viewer)->getJson('/api/v1/hrm/payroll-runs');

        $response->assertStatus(403);
    }

    public function test_viewer_cannot_approve_or_pay_a_payroll_run(): void
    {
        $this->actingAs($this->viewer)
            ->postJson("/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/approve")
            ->assertStatus(403);

        $this->actingAs($this->viewer)
            ->postJson("/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/mark-paid")
            ->assertStatus(403);

        $this->assertSame('calculated', $this->payrollRun->fresh()->status);
    }

    public function test_manager_can_view_payroll_but_cannot_approve_it(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/v1/hrm/payroll-runs')
            ->assertOk();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/approve")
            ->assertStatus(403);

        $this->assertSame('calculated', $this->payrollRun->fresh()->status);
    }

    public function test_admin_can_approve_a_calculated_run(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $this->payrollRun->fresh()->status);
    }

    public function test_salary_is_hidden_without_payroll_permission(): void
    {
        $viewerResponse = $this->actingAs($this->viewer)->getJson('/api/v1/hrm/contracts');
        $viewerResponse->assertOk();
        $viewerContract = collect($viewerResponse->json('data'))->firstWhere('id', $this->contract->id);
        $this->assertNotNull($viewerContract);
        $this->assertArrayNotHasKey('base_monthly_salary', $viewerContract, 'Salary must be hidden from a VIEWER.');

        $managerResponse = $this->actingAs($this->manager)->getJson('/api/v1/hrm/contracts');
        $managerResponse->assertOk();
        $managerContract = collect($managerResponse->json('data'))->firstWhere('id', $this->contract->id);
        $this->assertNotNull($managerContract);
        $this->assertSame(5000.0, (float) $managerContract['base_monthly_salary'], 'A payroll-permitted role sees the salary.');
    }

    public function test_viewer_can_still_read_the_employee_directory(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/hrm/employees')
            ->assertOk();
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(function (string $tenantId, string $key) use ($isEntitled) {
                return [
                    'key' => $key,
                    'is_entitled' => $isEntitled,
                    'source' => $isEntitled ? 'tier_included' : null,
                    'name' => strtoupper($key),
                ];
            });

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }
}

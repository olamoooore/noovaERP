<?php

namespace Modules\HRM\Tests\Feature;

use App\Models\User;
use Modules\HRM\Models\Contract;
use Modules\HRM\Models\Employee;
use Modules\HRM\Models\LeaveRequest;
use Modules\HRM\Models\PayrollRun;
use Modules\HRM\Tests\HrmTestCase;

class PayrollApiTest extends HrmTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makeActiveEmployeeWithContract(float $salary = 5000.00): array
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $contract = Contract::factory()->create([
            'employee_id' => $employee->id,
            'status' => 'active',
            'base_monthly_salary' => $salary,
            'start_date' => '2026-01-01',
            'end_date' => null,
        ]);

        return [$employee, $contract];
    }

    // -------------------------------------------------------------------------
    // Run creation
    // -------------------------------------------------------------------------

    public function test_can_create_payroll_run(): void
    {
        $response = $this->postJson('/api/v1/hrm/payroll-runs', [
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.period_start', '2026-03-01');
        $this->assertDatabaseHas('hrm_payroll_runs', ['period_start' => '2026-03-01']);
    }

    public function test_cannot_create_overlapping_run(): void
    {
        PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'draft',
        ]);

        $response = $this->postJson('/api/v1/hrm/payroll-runs', [
            'period_start' => '2026-03-15',
            'period_end' => '2026-04-15',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'A payroll run already exists that overlaps with this period.');
    }

    public function test_create_run_requires_period_dates(): void
    {
        $response = $this->postJson('/api/v1/hrm/payroll-runs', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['period_start', 'period_end']);
    }

    // -------------------------------------------------------------------------
    // Calculation
    // -------------------------------------------------------------------------

    public function test_can_calculate_payroll(): void
    {
        [$employee] = $this->makeActiveEmployeeWithContract(6000.00);

        $run = PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'draft',
        ]);

        $response = $this->postJson("/api/v1/hrm/payroll-runs/{$run->id}/calculate");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'calculated');
        $response->assertJsonPath('data.total_gross', '6000.00');
        $response->assertJsonPath('data.total_net', '6000.00');

        $this->assertDatabaseHas('hrm_payrolls', [
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
        ]);
    }

    public function test_calculation_skips_inactive_employees(): void
    {
        $terminated = Employee::factory()->create(['status' => 'terminated']);
        Contract::factory()->create([
            'employee_id' => $terminated->id,
            'status' => 'active',
            'base_monthly_salary' => 5000,
            'start_date' => '2026-01-01',
        ]);

        $run = PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'draft',
        ]);

        $this->postJson("/api/v1/hrm/payroll-runs/{$run->id}/calculate");

        $this->assertDatabaseMissing('hrm_payrolls', [
            'payroll_run_id' => $run->id,
            'employee_id' => $terminated->id,
        ]);
    }

    public function test_calculation_applies_unpaid_leave_deduction(): void
    {
        [$employee] = $this->makeActiveEmployeeWithContract(3100.00);

        // 1 day of approved unpaid leave in the period (March has 31 days)
        // daily_rate = 3100 / 31 = 100.00
        // deduction  = 1 * 100.00 = 100.00
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => 'unpaid',
            'status' => 'approved',
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-10',
        ]);

        $run = PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'draft',
        ]);

        $response = $this->postJson("/api/v1/hrm/payroll-runs/{$run->id}/calculate");

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_deductions', '100.00');
        $response->assertJsonPath('data.total_net', '3000.00');

        $this->assertDatabaseHas('hrm_payroll_items', [
            'category' => 'leave_deduction',
            'amount' => '100.00',
        ]);
    }

    public function test_cannot_calculate_non_draft_run(): void
    {
        $run = PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'calculated',
        ]);

        $response = $this->postJson("/api/v1/hrm/payroll-runs/{$run->id}/calculate");

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Approval
    // -------------------------------------------------------------------------

    public function test_can_approve_calculated_run(): void
    {
        $run = PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'calculated',
        ]);

        $response = $this->postJson("/api/v1/hrm/payroll-runs/{$run->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'approved');
        $this->assertNotNull($run->fresh()->approved_at);
    }

    public function test_cannot_approve_draft_run(): void
    {
        $run = PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'draft',
        ]);

        $response = $this->postJson("/api/v1/hrm/payroll-runs/{$run->id}/approve");

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Mark paid
    // -------------------------------------------------------------------------

    public function test_can_mark_paid(): void
    {
        $run = PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'approved',
        ]);

        $response = $this->postJson("/api/v1/hrm/payroll-runs/{$run->id}/mark-paid");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'paid');
    }

    public function test_cannot_mark_paid_unless_approved(): void
    {
        $run = PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'calculated',
        ]);

        $response = $this->postJson("/api/v1/hrm/payroll-runs/{$run->id}/mark-paid");

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Recalculation
    // -------------------------------------------------------------------------

    public function test_can_recalculate_calculated_run(): void
    {
        [$employee] = $this->makeActiveEmployeeWithContract(6000.00);

        $run = PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'calculated',
        ]);

        $response = $this->postJson("/api/v1/hrm/payroll-runs/{$run->id}/recalculate");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'calculated');
        $response->assertJsonPath('data.total_gross', '6000.00');

        $this->assertDatabaseHas('hrm_payrolls', [
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
        ]);
    }

    public function test_cannot_recalculate_approved_run(): void
    {
        $run = PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'approved',
        ]);

        $response = $this->postJson("/api/v1/hrm/payroll-runs/{$run->id}/recalculate");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Only a calculated payroll run can be recalculated.');
    }

    public function test_cannot_recalculate_paid_run(): void
    {
        $run = PayrollRun::create([
            'reference' => 'PAY-2026-03',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => 'paid',
        ]);

        $response = $this->postJson("/api/v1/hrm/payroll-runs/{$run->id}/recalculate");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Only a calculated payroll run can be recalculated.');
    }
}

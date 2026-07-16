<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\HRM\Models\AttendanceDailySummary;
use Modules\HRM\Models\AttendanceEvent;
use Modules\HRM\Models\Contract;
use Modules\HRM\Models\Employee;
use Modules\HRM\Models\LeaveRequest;
use Modules\HRM\Models\PayrollRun;
use Modules\HRM\Models\Timeshift;

/**
 * HRM Demo Seeder
 *
 * Seeds realistic demo data covering all HRM module areas so that every
 * page can be tested end-to-end immediately after a fresh migration.
 *
 * Usage (run inside a tenant context):
 *   php artisan tinker
 *   $tenant = App\Models\Tenant::first();
 *   $tenant->run(fn() => Artisan::call('db:seed', [
 *       '--class' => 'Modules\\HRM\\Database\\Seeders\\HRMDemoSeeder',
 *       '--force' => true,
 *   ]));
 *
 * Or from a central Artisan call after tenancy is initialised:
 *   php artisan tenants:artisan "db:seed --class=Modules\\HRM\\Database\\Seeders\\HRMDemoSeeder --force"
 */
class HRMDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Timeshift ─────────────────────────────────────────────────────────
        $timeshift = Timeshift::firstOrCreate(
            ['name' => 'Standard (Mon–Fri 09:00–17:00)'],
            [
                'schedule' => [
                    ['day' => 'monday',    'start' => '09:00', 'end' => '17:00'],
                    ['day' => 'tuesday',   'start' => '09:00', 'end' => '17:00'],
                    ['day' => 'wednesday', 'start' => '09:00', 'end' => '17:00'],
                    ['day' => 'thursday',  'start' => '09:00', 'end' => '17:00'],
                    ['day' => 'friday',    'start' => '09:00', 'end' => '17:00'],
                ],
                'is_default' => true,
            ]
        );

        // ── 2. Employees ─────────────────────────────────────────────────────────
        $ahmed = Employee::firstOrCreate(
            ['email' => 'ahmed.hassan@demo.hrm'],
            [
                'name' => 'Ahmed Hassan',
                'contact_number' => '+966-50-111-0001',
                'status' => 'active',
            ]
        );

        $sara = Employee::firstOrCreate(
            ['email' => 'sara.ali@demo.hrm'],
            [
                'name' => 'Sara Ali',
                'contact_number' => '+966-50-222-0002',
                'status' => 'active',
            ]
        );

        $khalid = Employee::firstOrCreate(
            ['email' => 'khalid.omar@demo.hrm'],
            [
                'name' => 'Khalid Omar',
                'contact_number' => '+966-50-333-0003',
                'status' => 'probation',
            ]
        );

        // ── 3. Contracts ─────────────────────────────────────────────────────────
        Contract::firstOrCreate(
            ['employee_id' => $ahmed->id, 'employment_type' => 'full_time'],
            [
                'job_title' => 'Senior Software Engineer',
                'status' => 'active',
                'base_monthly_salary' => 12000.00,
                'overtime_eligible' => true,
                'timeshift_id' => $timeshift->id,
                'fixed_working_days' => 22,
                'paid_leaves' => 21,
                'start_date' => '2025-01-01',
            ]
        );

        Contract::firstOrCreate(
            ['employee_id' => $sara->id, 'employment_type' => 'full_time'],
            [
                'job_title' => 'HR Manager',
                'status' => 'active',
                'base_monthly_salary' => 10500.00,
                'overtime_eligible' => false,
                'timeshift_id' => $timeshift->id,
                'fixed_working_days' => 22,
                'paid_leaves' => 21,
                'start_date' => '2025-03-01',
            ]
        );

        Contract::firstOrCreate(
            ['employee_id' => $khalid->id, 'employment_type' => 'part_time'],
            [
                'job_title' => 'Junior Developer',
                'status' => 'onboarding',
                'base_monthly_salary' => 5000.00,
                'overtime_eligible' => false,
                'timeshift_id' => $timeshift->id,
                'fixed_working_days' => 15,
                'paid_leaves' => 10,
                'start_date' => '2026-03-01',
            ]
        );

        // ── 4. Leave Requests ─────────────────────────────────────────────────────
        LeaveRequest::firstOrCreate(
            ['employee_id' => $ahmed->id, 'start_date' => '2026-04-10'],
            [
                'end_date' => '2026-04-12',
                'leave_type' => 'vacation',
                'status' => 'approved',
                'message' => 'Annual leave — family trip.',
                'feedback' => 'Approved. Enjoy your time off.',
            ]
        );

        LeaveRequest::firstOrCreate(
            ['employee_id' => $sara->id, 'start_date' => '2026-04-15'],
            [
                'end_date' => '2026-04-15',
                'leave_type' => 'sick',
                'status' => 'pending',
                'message' => 'Not feeling well — doctor appointment.',
            ]
        );

        LeaveRequest::firstOrCreate(
            ['employee_id' => $khalid->id, 'start_date' => '2026-04-20'],
            [
                'end_date' => '2026-04-20',
                'leave_type' => 'personal',
                'status' => 'rejected',
                'message' => 'Personal errand.',
                'feedback' => 'Cannot approve during probation period without prior notice.',
            ]
        );

        // ── 5. Attendance Events ──────────────────────────────────────────────────
        // Ahmed — 2026-04-01
        AttendanceEvent::firstOrCreate(
            ['source_event_id' => 'DEMO-AHMED-20260401-IN'],
            [
                'employee_id' => $ahmed->id,
                'event_type' => 'check_in',
                'captured_at' => '2026-04-01 08:55:00',
                'status' => 'processed',
                'device_id' => 'TERMINAL-01',
            ]
        );
        AttendanceEvent::firstOrCreate(
            ['source_event_id' => 'DEMO-AHMED-20260401-OUT'],
            [
                'employee_id' => $ahmed->id,
                'event_type' => 'check_out',
                'captured_at' => '2026-04-01 17:05:00',
                'status' => 'processed',
                'device_id' => 'TERMINAL-01',
            ]
        );

        // Sara — 2026-04-01
        AttendanceEvent::firstOrCreate(
            ['source_event_id' => 'DEMO-SARA-20260401-IN'],
            [
                'employee_id' => $sara->id,
                'event_type' => 'check_in',
                'captured_at' => '2026-04-01 09:10:00',
                'status' => 'processed',
                'device_id' => 'TERMINAL-01',
            ]
        );
        AttendanceEvent::firstOrCreate(
            ['source_event_id' => 'DEMO-SARA-20260401-OUT'],
            [
                'employee_id' => $sara->id,
                'event_type' => 'check_out',
                'captured_at' => '2026-04-01 17:00:00',
                'status' => 'processed',
                'device_id' => 'TERMINAL-01',
            ]
        );

        // Khalid — 2026-04-02 (check-in only — missing check-out exception)
        AttendanceEvent::firstOrCreate(
            ['source_event_id' => 'DEMO-KHALID-20260402-IN'],
            [
                'employee_id' => $khalid->id,
                'event_type' => 'check_in',
                'captured_at' => '2026-04-02 09:05:00',
                'status' => 'processed',
                'device_id' => 'TERMINAL-02',
            ]
        );

        // ── 6. Attendance Daily Summaries ─────────────────────────────────────────
        AttendanceDailySummary::firstOrCreate(
            ['employee_id' => $ahmed->id, 'date' => '2026-04-01'],
            [
                'first_check_in' => '08:55:00',
                'last_check_out' => '17:05:00',
                'worked_minutes' => 490,   // 8h 10m
                'status' => 'present',
                'has_exception' => false,
                'exception_type' => 'none',
            ]
        );

        AttendanceDailySummary::firstOrCreate(
            ['employee_id' => $sara->id, 'date' => '2026-04-01'],
            [
                'first_check_in' => '09:10:00',
                'last_check_out' => '17:00:00',
                'worked_minutes' => 470,   // 7h 50m
                'status' => 'present',
                'has_exception' => false,
                'exception_type' => 'none',
            ]
        );

        AttendanceDailySummary::firstOrCreate(
            ['employee_id' => $khalid->id, 'date' => '2026-04-02'],
            [
                'first_check_in' => '09:05:00',
                'last_check_out' => null,
                'worked_minutes' => null,
                'status' => 'partial',
                'has_exception' => true,
                'exception_type' => 'missing_check_out',
            ]
        );

        // ── 7. Payroll Run ────────────────────────────────────────────────────────
        $payrollRun = PayrollRun::firstOrCreate(
            ['reference' => 'PR-2026-03'],
            [
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-31',
                'status' => 'calculated',
                'currency' => 'USD',
                'total_gross' => 22500.00,
                'total_deductions' => 2250.00,
                'total_net' => 20250.00,
                'notes' => 'March 2026 payroll — demo data.',
            ]
        );

        // Payroll line items (Payroll model = per-employee summary inside a run)
        \Modules\HRM\Models\Payroll::firstOrCreate(
            ['payroll_run_id' => $payrollRun->id, 'employee_id' => $ahmed->id],
            [
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-31',
                'base_monthly_salary' => 12000.00,
                'net_pay' => 10800.00,
                'status' => 'finalized',
                'policy_snapshot' => [
                    'base_monthly_salary' => 12000.00,
                    'fixed_working_days' => 22,
                    'overtime_eligible' => true,
                ],
            ]
        );

        \Modules\HRM\Models\Payroll::firstOrCreate(
            ['payroll_run_id' => $payrollRun->id, 'employee_id' => $sara->id],
            [
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-31',
                'base_monthly_salary' => 10500.00,
                'net_pay' => 9450.00,
                'status' => 'finalized',
                'policy_snapshot' => [
                    'base_monthly_salary' => 10500.00,
                    'fixed_working_days' => 22,
                    'overtime_eligible' => false,
                ],
            ]
        );

        if (isset($this->command)) {
            $this->command->info('HRM demo data seeded successfully.');
            $this->command->info('  Employees : 3 (Ahmed Hassan, Sara Ali, Khalid Omar)');
            $this->command->info('  Contracts : 3 (active x2, onboarding x1)');
            $this->command->info('  Leave     : 3 (approved, pending, rejected)');
            $this->command->info('  Events    : 5 attendance events');
            $this->command->info('  Summaries : 3 (present x2, partial x1 with exception)');
            $this->command->info('  Payroll   : 1 run (PR-2026-03, calculated)');
        }
    }
}

<?php

namespace Modules\HRM\Services;

use Carbon\Carbon;
use Modules\HRM\Models\Employee;
use Modules\HRM\Models\LeaveRequest;
use Modules\HRM\Models\Payroll;
use Modules\HRM\Models\PayrollItem;
use Modules\HRM\Models\PayrollRun;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PayrollService
{
    /**
     * Calculate payroll for a draft run.
     *
     * Steps:
     *  1. Guard — run must be in 'draft' status
     *  2. Delete any previously generated entries (safe recalculation)
     *  3. Select active employees with an active contract covering the period
     *  4. For each employee: compute base salary, unpaid-leave deduction, net pay
     *  5. Persist Payroll + PayrollItem records
     *  6. Update run totals and set status = 'calculated'
     */
    public function calculate(PayrollRun $run): PayrollRun
    {
        if ($run->status !== 'draft') {
            throw new \LogicException(
                "Cannot calculate payroll run — status is '{$run->status}', expected 'draft'."
            );
        }

        // Wipe any stale entries from a previous (partial) calculation attempt
        Payroll::where('payroll_run_id', $run->id)->forceDelete();

        $periodStart = $run->period_start;
        $periodEnd   = $run->period_end;

        // Active employees who have at least one active contract covering this period
        $employees = Employee::where('status', 'active')
            ->with(['contracts' => function ($q) use ($periodStart, $periodEnd) {
                $q->where('status', 'active')
                  ->where('start_date', '<=', $periodEnd)
                  ->where(function ($q2) use ($periodStart) {
                      $q2->whereNull('end_date')
                         ->orWhere('end_date', '>=', $periodStart);
                  });
            }])
            ->get()
            ->filter(fn ($e) => $e->contracts->isNotEmpty());

        $totalGross      = 0.0;
        $totalDeductions = 0.0;

        foreach ($employees as $employee) {
            $contract   = $employee->contracts->first();
            $baseSalary = (float) $contract->base_monthly_salary;
            $deduction  = $this->calculateLeaveDeduction(
                $employee,
                $periodStart,
                $periodEnd,
                $baseSalary
            );
            $netPay = max(0.0, $baseSalary - $deduction);

            $payroll = Payroll::create([
                'payroll_run_id'      => $run->id,
                'employee_id'         => $employee->id,
                'contract_id'         => $contract->id,
                'period_start'        => $periodStart,
                'period_end'          => $periodEnd,
                'base_monthly_salary' => $baseSalary,
                'net_pay'             => $netPay,
                'status'              => 'draft',
                'generated_by'        => TenantUserHelper::resolveTenantUserId(),
            ]);

            if ($deduction > 0) {
                PayrollItem::create([
                    'payroll_id'  => $payroll->id,
                    'type'        => 'deduction',
                    'category'    => 'leave_deduction',
                    'amount'      => $deduction,
                    'description' => 'Unpaid leave deduction',
                ]);
            }

            $totalGross      += $baseSalary;
            $totalDeductions += $deduction;
        }

        $run->update([
            'total_gross'      => round($totalGross, 2),
            'total_deductions' => round($totalDeductions, 2),
            'total_net'        => round($totalGross - $totalDeductions, 2),
            'status'           => 'calculated',
        ]);

        return $run->fresh(['payrolls.employee', 'payrolls.items']);
    }

    /**
     * Recalculate payroll for a run that is already in 'calculated' state.
     *
     * Allows corrections before approval — resets to draft, then re-runs calculate().
     */
    public function recalculate(PayrollRun $run): PayrollRun
    {
        if ($run->status !== 'calculated') {
            throw new \LogicException(
                "Cannot recalculate payroll run — status is '{$run->status}', expected 'calculated'."
            );
        }

        // Temporarily reset to draft so calculate() guard passes
        $run->update(['status' => 'draft']);

        return $this->calculate($run->fresh());
    }

    /**
     * Calculate the unpaid-leave deduction for one employee within the payroll period.
     *
     * Formula:
     *   daily_rate  = base_salary / total_calendar_days_in_period
     *   deduction   = approved_unpaid_leave_days_in_period * daily_rate
     *
     * Leave dates are clamped to the payroll period to avoid double-counting
     * leave that spills across period boundaries.
     */
    private function calculateLeaveDeduction(
        Employee $employee,
        Carbon $periodStart,
        Carbon $periodEnd,
        float $baseSalary
    ): float {
        $totalDays = $periodStart->diffInDays($periodEnd) + 1;
        $dailyRate = $baseSalary / $totalDays;

        $unpaidLeaveDays = LeaveRequest::where('employee_id', $employee->id)
            ->where('leave_type', 'unpaid')
            ->where('status', 'approved')
            ->where('start_date', '<=', $periodEnd)
            ->where('end_date', '>=', $periodStart)
            ->get()
            ->sum(function (LeaveRequest $lr) use ($periodStart, $periodEnd) {
                // Clamp leave dates to period boundaries
                $start = $lr->start_date->max($periodStart);
                $end   = $lr->end_date->min($periodEnd);

                return $start->diffInDays($end) + 1;
            });

        return round((float) $unpaidLeaveDays * $dailyRate, 2);
    }
}

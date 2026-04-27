<?php

use Illuminate\Support\Facades\Route;
use Modules\HRM\Http\Controllers\HRMHealthController;
use Modules\HRM\Http\Controllers\EmployeeController;
use Modules\HRM\Http\Controllers\ContractController;
use Modules\HRM\Http\Controllers\LookupController;
use Modules\HRM\Http\Controllers\TimeshiftController;
use Modules\HRM\Http\Controllers\AttendanceController;
use Modules\HRM\Http\Controllers\LeaveRequestController;
use Modules\HRM\Http\Controllers\PayrollController;
use Modules\HRM\Http\Controllers\EmployeeUserLinkController;
use Modules\HRM\Http\Controllers\AttendanceEventController;
use Modules\HRM\Http\Controllers\AttendanceSummaryController;
use Modules\HRM\Http\Controllers\EmployeeBiometricSubjectController;

Route::middleware(['auth:sanctum', 'entitlement:hrm'])->prefix('hrm')->group(function () {

    // Health check
    Route::get('/health', [HRMHealthController::class, 'check']);

    // Lookups (departments & locations for dropdowns)
    Route::get('/lookup/departments', [LookupController::class, 'departments']);
    Route::get('/lookup/locations',   [LookupController::class, 'locations']);

    // Employees
    Route::apiResource('employees', EmployeeController::class);

    // Contracts
    Route::apiResource('contracts', ContractController::class);

    // Timeshifts
    Route::apiResource('timeshifts', TimeshiftController::class);

    // Attendance (manual)
    Route::apiResource('attendance', AttendanceController::class);

    // Leave Requests
    Route::apiResource('leave-requests', LeaveRequestController::class);
    Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
    Route::post('leave-requests/{leaveRequest}/reject',  [LeaveRequestController::class, 'reject']);

    // Employee Identity — user links
    Route::get   ('employees/{employee}/user-links',          [EmployeeUserLinkController::class, 'index']);
    Route::post  ('employees/{employee}/user-links',          [EmployeeUserLinkController::class, 'store']);
    Route::delete('employees/{employee}/user-links/{link}',   [EmployeeUserLinkController::class, 'destroy']);

    // Employee Biometric Subjects (subject_code only — no raw biometric data)
    Route::get   ('employees/{employee}/biometric-subjects',            [EmployeeBiometricSubjectController::class, 'index']);
    Route::post  ('employees/{employee}/biometric-subjects',            [EmployeeBiometricSubjectController::class, 'store']);
    Route::delete('employees/{employee}/biometric-subjects/{subject}',  [EmployeeBiometricSubjectController::class, 'destroy']);

    // Attendance Events (event-driven — separate from legacy hrm_attendances)
    Route::get ('attendance-events',    [AttendanceEventController::class, 'index']);
    Route::post('attendance-events',    [AttendanceEventController::class, 'ingest']);

    // Attendance Daily Summaries
    Route::get ('attendance-summaries',       [AttendanceSummaryController::class, 'index']);
    Route::get ('attendance-summaries/{summary}', [AttendanceSummaryController::class, 'show']);

    // Payroll Runs
    Route::apiResource('payroll-runs', PayrollController::class)->only(['index', 'store', 'show']);
    Route::post('payroll-runs/{payrollRun}/calculate',   [PayrollController::class, 'calculate']);
    Route::post('payroll-runs/{payrollRun}/recalculate', [PayrollController::class, 'recalculate']);
    Route::post('payroll-runs/{payrollRun}/approve',     [PayrollController::class, 'approve']);
    Route::post('payroll-runs/{payrollRun}/mark-paid',   [PayrollController::class, 'markPaid']);
});

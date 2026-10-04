<?php

use App\Http\Middleware\RequirePermission;
use Illuminate\Support\Facades\Route;
use Modules\HRM\Http\Controllers\AttendanceController;
use Modules\HRM\Http\Controllers\AttendanceEventController;
use Modules\HRM\Http\Controllers\AttendanceSummaryController;
use Modules\HRM\Http\Controllers\ContractController;
use Modules\HRM\Http\Controllers\EmployeeBiometricSubjectController;
use Modules\HRM\Http\Controllers\EmployeeController;
use Modules\HRM\Http\Controllers\EmployeeUserLinkController;
use Modules\HRM\Http\Controllers\HRMHealthController;
use Modules\HRM\Http\Controllers\LeaveRequestController;
use Modules\HRM\Http\Controllers\LookupController;
use Modules\HRM\Http\Controllers\PayrollController;
use Modules\HRM\Http\Controllers\TimeshiftController;

Route::middleware(['auth:sanctum', 'entitlement:hrm'])->prefix('hrm')->group(function () {

    // #787 (Y1): every HRM route declares the permission it needs. Payroll
    // and salary access carries its own permissions (hrm.payroll.*), never
    // granted to VIEWER/CASHIER; approve and pay are split from view so a
    // MANAGER cannot create → calculate → approve → pay alone.
    Route::middleware(RequirePermission::using('hrm.view'))->group(function () {
        // Health check
        Route::get('/health', [HRMHealthController::class, 'check']);

        // Lookups (departments & locations for dropdowns)
        Route::get('/lookup/departments', [LookupController::class, 'departments']);
        Route::get('/lookup/locations', [LookupController::class, 'locations']);

        // Employees
        Route::apiResource('employees', EmployeeController::class)->only(['index', 'show']);

        // Contracts
        Route::apiResource('contracts', ContractController::class)->only(['index', 'show']);

        // Timeshifts
        Route::apiResource('timeshifts', TimeshiftController::class)->only(['index', 'show']);

        // Attendance (manual)
        Route::apiResource('attendance', AttendanceController::class)->only(['index', 'show']);

        // Leave Requests
        Route::apiResource('leave-requests', LeaveRequestController::class)->only(['index', 'show']);

        // Employee Identity — user links
        Route::get('employees/{employee}/user-links', [EmployeeUserLinkController::class, 'index']);

        // Employee Biometric Subjects (subject_code only — no raw biometric data)
        Route::get('employees/{employee}/biometric-subjects', [EmployeeBiometricSubjectController::class, 'index']);

        // Attendance Events (event-driven — separate from legacy hrm_attendances)
        Route::get('attendance-events', [AttendanceEventController::class, 'index']);

        // Attendance Daily Summaries
        Route::get('attendance-summaries', [AttendanceSummaryController::class, 'index']);
        Route::get('attendance-summaries/{summary}', [AttendanceSummaryController::class, 'show']);
    });

    Route::middleware(RequirePermission::using('hrm.write'))->group(function () {
        // Employees
        Route::apiResource('employees', EmployeeController::class)->only(['store', 'update', 'destroy']);

        // Contracts
        Route::apiResource('contracts', ContractController::class)->only(['store', 'update', 'destroy']);

        // Timeshifts
        Route::apiResource('timeshifts', TimeshiftController::class)->only(['store', 'update', 'destroy']);

        // Attendance (manual)
        Route::apiResource('attendance', AttendanceController::class)->only(['store', 'update', 'destroy']);

        // Leave Requests
        Route::apiResource('leave-requests', LeaveRequestController::class)->only(['store', 'update', 'destroy']);
        Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
        Route::post('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);

        // Employee Identity — user links
        Route::post('employees/{employee}/user-links', [EmployeeUserLinkController::class, 'store']);
        Route::delete('employees/{employee}/user-links/{link}', [EmployeeUserLinkController::class, 'destroy']);

        // Employee Biometric Subjects
        Route::post('employees/{employee}/biometric-subjects', [EmployeeBiometricSubjectController::class, 'store']);
        Route::delete('employees/{employee}/biometric-subjects/{subject}', [EmployeeBiometricSubjectController::class, 'destroy']);

        // Attendance Events ingestion
        Route::post('attendance-events', [AttendanceEventController::class, 'ingest']);
    });

    // Payroll runs: reading and preparing runs needs hrm.payroll.view;
    // approving and paying have their own permissions (AC #787).
    Route::middleware(RequirePermission::using('hrm.payroll.view'))->group(function () {
        Route::apiResource('payroll-runs', PayrollController::class)->only(['index', 'store', 'show']);
        Route::post('payroll-runs/{payrollRun}/calculate', [PayrollController::class, 'calculate']);
        Route::post('payroll-runs/{payrollRun}/recalculate', [PayrollController::class, 'recalculate']);
    });

    Route::post('payroll-runs/{payrollRun}/approve', [PayrollController::class, 'approve'])
        ->middleware(RequirePermission::using('hrm.payroll.approve'));

    Route::post('payroll-runs/{payrollRun}/mark-paid', [PayrollController::class, 'markPaid'])
        ->middleware(RequirePermission::using('hrm.payroll.pay'));
});

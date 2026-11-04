<?php

use App\Http\Controllers\Api\Admin\CompanyController;
use App\Http\Controllers\Api\Admin\ContributionDeductionSettingController;
use App\Http\Controllers\Api\Admin\ContributionRateBracketController;
use App\Http\Controllers\Api\Admin\ContributionRateScheduleController;
use App\Http\Controllers\Api\Admin\ContributionTypeController;
use App\Http\Controllers\Api\Admin\DepartmentController;
use App\Http\Controllers\Api\Admin\EmployeeController;
use App\Http\Controllers\Api\Admin\EmployeeLoanController;
use App\Http\Controllers\Api\Admin\EmployeeLoanTransactionController;
use App\Http\Controllers\Api\Admin\EmployeeSalaryRateController;
use App\Http\Controllers\Api\Admin\EmploymentDocumentsController;
use App\Http\Controllers\Api\Admin\EmploymentTypeController;
use App\Http\Controllers\Api\Admin\LateAdjustmentController;
use App\Http\Controllers\Api\Admin\LeaveApprovalController;
use App\Http\Controllers\Api\Admin\LeaveCreditTransactionController;
use App\Http\Controllers\Api\Admin\LeavePoliciesController;
use App\Http\Controllers\Api\Admin\LeaveTypesController;
use App\Http\Controllers\Api\Admin\LoanTypesController;
use App\Http\Controllers\Api\Admin\OvertimeController;
use App\Http\Controllers\Api\Admin\PayrollGroupController;
use App\Http\Controllers\Api\Admin\PayrollPeriodController;
use App\Http\Controllers\Api\Admin\PositionController;
use App\Http\Controllers\Api\Admin\SalaryAdjustmentController;
use App\Http\Controllers\Api\Admin\SalaryTypeController;
use App\Http\Controllers\Api\Admin\ScheduleController;
use App\Http\Controllers\Api\Admin\ScheduleTypeController;
use App\Http\Controllers\Api\Admin\SystemUserController;
use App\Http\Controllers\Api\Admin\TimeLogController;
use App\Http\Controllers\Api\Admin\TimeLogCorrectionRequestController;
use App\Http\Controllers\Api\Admin\TimeLogImportController;
use App\Http\Controllers\Api\Admin\UndertimeController;
use App\Http\Controllers\Api\Admin\ManualTimeEntryController;
use Illuminate\Support\Facades\Route;

// ============================================
// EMPLOYEES
// ============================================

Route::get('/employees/filter-options', [
    EmployeeController::class,
    'filterOptions',
]);

Route::get('/employees', [EmployeeController::class, 'index']);
Route::post('/employees', [EmployeeController::class, 'store']);
Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
Route::patch('/employees/{employee}/reactivate', [EmployeeController::class, 'reactivate']);
Route::match(['put', 'patch'], '/employees/{employee}', [EmployeeController::class, 'update']);
Route::patch('/employees/{employee}/deactivate', [EmployeeController::class, 'deactivate']);

// ============================================
// SYSTEM USERS
// ============================================

Route::get('/system-users', [SystemUserController::class, 'index']);
Route::get('/system-users/employees/{employee}/edit', [SystemUserController::class, 'editEmployee']);
Route::post('/system-users/employees/{employee}/setup-account', [SystemUserController::class, 'setupAccount']);
Route::get('/system-users/{user}/edit', [SystemUserController::class, 'edit']);
Route::match(['put', 'patch'], '/system-users/{user}', [SystemUserController::class, 'update']);
Route::patch('/system-users/{user}/role', [SystemUserController::class, 'updateRole']);

// ============================================
// SCHEDULES, EMPLOYMENT & DEPARTMENTS
// ============================================

Route::get('schedule-types', [ScheduleTypeController::class, 'index']);
Route::apiResource('employment-types', EmploymentTypeController::class);
Route::get('employment-documents', [EmploymentDocumentsController::class, 'index']);
Route::apiResource('work-schedules', ScheduleController::class);

Route::apiResource('departments', DepartmentController::class);
Route::apiResource('positions', PositionController::class);

// ============================================
// CONTRIBUTIONS & COMPANIES
// ============================================

Route::apiResource('contribution-types', ContributionTypeController::class);
Route::apiResource('contribution-rate-schedules', ContributionRateScheduleController::class);
Route::apiResource('contribution-rate-brackets', ContributionRateBracketController::class);
Route::apiResource('contribution-deduction-settings', ContributionDeductionSettingController::class);
Route::apiResource('companies', CompanyController::class);
Route::get('company/current', [CompanyController::class, 'current']);

// ============================================
// PAYROLL
// ============================================

Route::get('salary-types', [SalaryTypeController::class, 'index']);
Route::get('payroll-groups', [PayrollGroupController::class, 'index']);
Route::apiResource('payroll-periods', PayrollPeriodController::class);

// ============================================
// LEAVE MANAGEMENT
// ============================================

Route::apiResource('leave-types', LeaveTypesController::class);
Route::apiResource('leave-policies', LeavePoliciesController::class);

Route::get('leave-approvals', [LeaveApprovalController::class, 'index']);
Route::post('leave-approvals/preview', [LeaveApprovalController::class, 'preview']);
Route::post('leave-approvals', [LeaveApprovalController::class, 'store']);
Route::patch('leave-approvals/{leaveRequest}/approve', [LeaveApprovalController::class, 'approve']);
Route::patch('leave-approvals/{leaveRequest}/reject', [LeaveApprovalController::class, 'reject']);
Route::patch('leave-approvals/{leaveRequest}/revert', [LeaveApprovalController::class, 'revert']);

// ============================================
// OVERTIME
// ============================================

Route::get('overtime-requests', [OvertimeController::class, 'index']);
Route::post('overtime-requests', [OvertimeController::class, 'store']);
Route::patch('overtime-requests/{overtimeRequest}/approve', [OvertimeController::class, 'approve']);
Route::patch('overtime-requests/{overtimeRequest}/reject', [OvertimeController::class, 'reject']);
Route::patch('overtime-requests/{overtimeRequest}/revert', [OvertimeController::class, 'revert']);

// ============================================
// UNDERTIME
// ============================================

Route::get('undertime-requests', [UndertimeController::class, 'index']);
Route::post('undertime-requests', [UndertimeController::class, 'store']);
Route::patch('undertime-requests/{undertimeRequest}/approve', [UndertimeController::class, 'approve']);
Route::patch('undertime-requests/{undertimeRequest}/reject', [UndertimeController::class, 'reject']);
Route::patch('undertime-requests/{undertimeRequest}/revert', [UndertimeController::class, 'revert']);

// ============================================
// LEAVE CREDIT TRANSACTIONS
// ============================================

Route::get('leave-credit-transactions', [LeaveCreditTransactionController::class, 'index']);
Route::get('leave-credit-balances', [LeaveCreditTransactionController::class, 'balances']);
Route::post('leave-credit-transactions/adjust', [LeaveCreditTransactionController::class, 'adjust']);

// ============================================
// LOAN TYPES
// ============================================

Route::get('/loan-types', [LoanTypesController::class, 'index']);
Route::post('/loan-types', [LoanTypesController::class, 'store']);
Route::get('/loan-types/{loanType}/edit', [LoanTypesController::class, 'edit']);
Route::match(['put', 'patch'], '/loan-types/{loanType}', [LoanTypesController::class, 'update']);
Route::delete('/loan-types/{loanType}', [LoanTypesController::class, 'destroy']);

// ============================================
// EMPLOYEE LOANS
// ============================================

Route::get('/employee-loans', [EmployeeLoanController::class, 'index']);
Route::post('/employee-loans', [EmployeeLoanController::class, 'store']);
Route::get('/employee-loans/{employeeLoan}', [EmployeeLoanController::class, 'show']);
Route::put('/employee-loans/{employeeLoan}', [EmployeeLoanController::class, 'update']);
Route::get('/employee-loan-transactions', [EmployeeLoanTransactionController::class, 'index']);
Route::get('/employee-loans/{employeeLoan}/transactions', [EmployeeLoanTransactionController::class, 'history']);

// ============================================
// EMPLOYEE SALARY RATES
// ============================================

Route::get('/employee-salary-rates/filter-options', [EmployeeSalaryRateController::class, 'filterOptions']);
Route::get('/employee-salary-rates', [EmployeeSalaryRateController::class, 'index']);
Route::get('/employee-salary-rates/{salarySetup}', [EmployeeSalaryRateController::class, 'show']);

// ============================================
// SALARY ADJUSTMENTS
// ============================================

Route::middleware('can:manage-salary-adjustments')
    ->group(function () {

        Route::get('salary-adjustments', [
            SalaryAdjustmentController::class,
            'index',
        ]);

        Route::post('salary-adjustments', [
            SalaryAdjustmentController::class,
            'store',
        ]);

        Route::patch('salary-adjustments/{salaryAdjustment}/approve', [
            SalaryAdjustmentController::class,
            'approve',
        ]);

        Route::patch('salary-adjustments/{salaryAdjustment}/reject', [
            SalaryAdjustmentController::class,
            'reject',
        ]);

        Route::patch('salary-adjustments/{salaryAdjustment}/apply', [
            SalaryAdjustmentController::class,
            'apply',
        ]);

        Route::get('salary-adjustments/{salaryAdjustment}', [
            SalaryAdjustmentController::class,
            'show',
        ]);
    });

// ============================================
// TIME LOGS
// ============================================

Route::post('time-logs/import', [TimeLogImportController::class, 'store']);
Route::get('time-logs', [TimeLogController::class, 'index']);
Route::get('time-logs/{timeLog}', [TimeLogController::class, 'show']);

// ============================================
// TIME LOG CORRECTION REQUESTS
// ============================================

Route::get(
    'time-log-corrections',
    [TimeLogCorrectionRequestController::class, 'index']
);

Route::post(
    'time-log-corrections',
    [TimeLogCorrectionRequestController::class, 'store']
);

Route::get(
    'time-logs/{timeLog}/correction-history',
    [TimeLogCorrectionRequestController::class, 'history']
);

Route::get(
    'time-log-corrections/{correction}',
    [TimeLogCorrectionRequestController::class, 'show']
);

Route::patch(
    'time-log-corrections/{correction}/approve',
    [TimeLogCorrectionRequestController::class, 'approve']
);

Route::patch(
    'time-log-corrections/{correction}/reject',
    [TimeLogCorrectionRequestController::class, 'reject']
);

// ============================================
// LATE ADJUSTMENTS
// ============================================


Route::get(
    'late-adjustments/eligible-logs',
    [LateAdjustmentController::class, 'eligibleLogs']
);

Route::get(
    'late-adjustments',
    [LateAdjustmentController::class, 'index']
);

Route::post(
    'late-adjustments',
    [LateAdjustmentController::class, 'store']
);

Route::get(
    'late-adjustments/{lateAdjustment}',
    [LateAdjustmentController::class, 'show']
);

Route::match(
    ['put', 'patch'],
    'late-adjustments/{lateAdjustment}',
    [LateAdjustmentController::class, 'update']
);

Route::patch(
    'late-adjustments/{lateAdjustment}/approve',
    [LateAdjustmentController::class, 'approve']
);

Route::patch(
    'late-adjustments/{lateAdjustment}/reject',
    [LateAdjustmentController::class, 'reject']
);


// ============================================
// MANUAL TIME IN / OUT
// ============================================


Route::patch(
    'manual-time-entries/{timeLog}',
    [ManualTimeEntryController::class, 'update']
);

Route::get(
    'manual-time-entries',
    [ManualTimeEntryController::class, 'index']
);

Route::post(
    'manual-time-entries',
    [ManualTimeEntryController::class, 'store']
);

Route::get(
    'manual-time-entries/{timeLog}',
    [ManualTimeEntryController::class, 'show']
);



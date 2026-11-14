<?php

use App\Http\Controllers\Api\Admin\CompanyCalendarEventController;
use App\Http\Controllers\Api\Admin\CompanyController;
use App\Http\Controllers\Api\Admin\ContributionChangeLogController;
use App\Http\Controllers\Api\Admin\ContributionDeductionSettingController;
use App\Http\Controllers\Api\Admin\ContributionRateBracketController;
use App\Http\Controllers\Api\Admin\ContributionRateScheduleController;
use App\Http\Controllers\Api\Admin\ContributionTypeController;
use App\Http\Controllers\Api\Admin\DepartmentController;
use App\Http\Controllers\Api\Admin\EmployeeController;
use App\Http\Controllers\Api\Admin\EmployeeLoanController;
use App\Http\Controllers\Api\Admin\EmployeeLoanTransactionController;
use App\Http\Controllers\Api\Admin\EmployeeSalaryRateController;
use App\Http\Controllers\Api\Admin\EmployeeTransferController;
use App\Http\Controllers\Api\Admin\EmploymentDocumentsController;
use App\Http\Controllers\Api\Admin\EmploymentTypeController;
use App\Http\Controllers\Api\Admin\HolidayCoverageController;
use App\Http\Controllers\Api\Admin\LateAdjustmentController;
use App\Http\Controllers\Api\Admin\LeaveApprovalController;
use App\Http\Controllers\Api\Admin\LeaveCreditTransactionController;
use App\Http\Controllers\Api\Admin\LeavePoliciesController;
use App\Http\Controllers\Api\Admin\LeaveTypesController;
use App\Http\Controllers\Api\Admin\LoanTypesController;
use App\Http\Controllers\Api\Admin\ManualTimeEntryController;
use App\Http\Controllers\Api\Admin\OvertimeController;
use App\Http\Controllers\Api\Admin\PayrollGenerationController;
use App\Http\Controllers\Api\Admin\PayrollGroupController;
use App\Http\Controllers\Api\Admin\PayrollPeriodController;
use App\Http\Controllers\Api\Admin\PayrollReadinessController;
use App\Http\Controllers\Api\Admin\PositionController;
use App\Http\Controllers\Api\Admin\RestDaySwitchRequestController;
use App\Http\Controllers\Api\Admin\SalaryAdjustmentController;
use App\Http\Controllers\Api\Admin\SalaryTypeController;
use App\Http\Controllers\Api\Admin\ScheduleController;
use App\Http\Controllers\Api\Admin\ScheduleTypeController;
use App\Http\Controllers\Api\Admin\SystemUserController;
use App\Http\Controllers\Api\Admin\TimeLogController;
use App\Http\Controllers\Api\Admin\TimeLogCorrectionRequestController;
use App\Http\Controllers\Api\Admin\TimeLogImportController;
use App\Http\Controllers\Api\Admin\UndertimeController;
use App\Http\Controllers\Api\Admin\WfhDocumentController;
use App\Http\Controllers\Api\Admin\WfhRequestController;
use App\Http\Controllers\Api\Admin\WorkCalendarController;
use App\Http\Controllers\Api\Admin\GenerateQrCodeController;
use App\Http\Controllers\Api\Admin\QrCodeGenerationController;

use App\Http\Controllers\Api\Admin\PayslipController;
use App\Http\Controllers\Api\Admin\EmployeePayrollController;

use App\Http\Controllers\Api\Admin\HolidayPayController;
use App\Http\Controllers\Api\Admin\HolidayPayRuleController;

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| NOTE ON ORDER
|--------------------------------------------------------------------------
| Static routes (e.g. /filter-options, /eligible-logs, /employees) must stay
| ABOVE their /{param} siblings, or the param route will swallow them.
*/

// ============================================
// COMPANY
// ============================================

Route::get('company/current', [CompanyController::class, 'current']);
Route::apiResource('companies', CompanyController::class);

// ============================================
// ORGANIZATION: DEPARTMENTS, POSITIONS, EMPLOYMENT
// ============================================

Route::apiResource('departments', DepartmentController::class);
Route::apiResource('positions', PositionController::class);
Route::apiResource('employment-types', EmploymentTypeController::class);
Route::get('employment-documents', [EmploymentDocumentsController::class, 'index']);

// ============================================
// EMPLOYEES
// ============================================

Route::get('employees/filter-options', [EmployeeController::class, 'filterOptions']);
Route::get('employees', [EmployeeController::class, 'index']);
Route::post('employees', [EmployeeController::class, 'store']);
Route::get('employees/{employee}', [EmployeeController::class, 'show']);
Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update']);
Route::patch('employees/{employee}/deactivate', [EmployeeController::class, 'deactivate']);
Route::patch('employees/{employee}/reactivate', [EmployeeController::class, 'reactivate']);

// ============================================
// EMPLOYEE DEPARTMENT TRANSFERS
// ============================================

Route::get('employee-department-transfers', [EmployeeTransferController::class, 'index']);
Route::post('employee-department-transfers', [EmployeeTransferController::class, 'store']);
Route::get('employee-department-transfers/{transfer}', [EmployeeTransferController::class, 'show']);
Route::match(['put', 'patch'], 'employee-department-transfers/{transfer}', [EmployeeTransferController::class, 'update']);
Route::delete('employee-department-transfers/{transfer}', [EmployeeTransferController::class, 'destroy']);
Route::patch('employee-department-transfers/{transfer}/approve', [EmployeeTransferController::class, 'approve']);
Route::patch('employee-department-transfers/{transfer}/reject', [EmployeeTransferController::class, 'reject']);
Route::post('employee-department-transfers/{transfer}/apply', [EmployeeTransferController::class, 'apply']);
Route::patch('employee-department-transfers/{transfer}/cancel', [EmployeeTransferController::class, 'cancel']);

// ============================================
// SYSTEM USERS
// ============================================

Route::get('system-users', [SystemUserController::class, 'index']);
Route::get('system-users/employees/{employee}/edit', [SystemUserController::class, 'editEmployee']);
Route::post('system-users/employees/{employee}/setup-account', [SystemUserController::class, 'setupAccount']);
Route::get('system-users/{user}/edit', [SystemUserController::class, 'edit']);
Route::match(['put', 'patch'], 'system-users/{user}', [SystemUserController::class, 'update']);
Route::patch('system-users/{user}/role', [SystemUserController::class, 'updateRole']);

// ============================================
// SCHEDULES
// ============================================

Route::get('schedule-types', [ScheduleTypeController::class, 'index']);
Route::apiResource('work-schedules', ScheduleController::class);

// ============================================
// SWITCH REST DAY REQUESTS
// ============================================

Route::get('switch-rest-day-requests', [RestDaySwitchRequestController::class, 'index']);
Route::post('switch-rest-day-requests', [RestDaySwitchRequestController::class, 'store']);
Route::get('switch-rest-day-requests/{restDaySwitchRequest}', [RestDaySwitchRequestController::class, 'show']);
Route::patch('switch-rest-day-requests/{restDaySwitchRequest}', [RestDaySwitchRequestController::class, 'update']);
Route::patch('switch-rest-day-requests/{restDaySwitchRequest}/approve', [RestDaySwitchRequestController::class, 'approve']);
Route::patch('switch-rest-day-requests/{restDaySwitchRequest}/reject', [RestDaySwitchRequestController::class, 'reject']);
Route::patch('switch-rest-day-requests/{restDaySwitchRequest}/cancel', [RestDaySwitchRequestController::class, 'cancel']);

// ============================================
// WORK CALENDAR, HOLIDAYS & CALENDAR EVENTS
// ============================================

Route::get('work-calendar', [WorkCalendarController::class, 'index']);
Route::post('work-calendar', [WorkCalendarController::class, 'store']);
Route::get('work-calendar/holidays', [WorkCalendarController::class, 'holidays']);
Route::post('work-calendar/holidays/import', [WorkCalendarController::class, 'import']);
Route::get('work-calendar/holidays/{holiday}/coverage', [HolidayCoverageController::class, 'show']);
Route::put('work-calendar/holidays/{holiday}/coverage', [HolidayCoverageController::class, 'update']);
Route::put('work-calendar/{holiday}', [WorkCalendarController::class, 'update']);
Route::delete('work-calendar/{holiday}', [WorkCalendarController::class, 'destroy']);

Route::post('calendar-events', [CompanyCalendarEventController::class, 'store']);
Route::put('calendar-events/{event}', [CompanyCalendarEventController::class, 'update']);
Route::delete('calendar-events/{event}', [CompanyCalendarEventController::class, 'destroy']);

// ============================================
// TIME LOGS
// ============================================

Route::post('time-logs/import', [TimeLogImportController::class, 'store']);
Route::get('time-logs', [TimeLogController::class, 'index']);
Route::get('time-logs/{timeLog}', [TimeLogController::class, 'show']);
Route::get('time-logs/{timeLog}/correction-history', [TimeLogCorrectionRequestController::class, 'history']);

// ============================================
// TIME LOG CORRECTION REQUESTS
// ============================================

Route::get('time-log-corrections', [TimeLogCorrectionRequestController::class, 'index']);
Route::post('time-log-corrections', [TimeLogCorrectionRequestController::class, 'store']);
Route::get('time-log-corrections/{correction}', [TimeLogCorrectionRequestController::class, 'show']);
Route::patch('time-log-corrections/{correction}/approve', [TimeLogCorrectionRequestController::class, 'approve']);
Route::patch('time-log-corrections/{correction}/reject', [TimeLogCorrectionRequestController::class, 'reject']);
Route::post('time-log-corrections/{correction}/apply', [TimeLogCorrectionRequestController::class, 'apply']);

// ============================================
// MANUAL TIME IN / OUT
// ============================================

Route::get('manual-time-entries', [ManualTimeEntryController::class, 'index']);
Route::post('manual-time-entries', [ManualTimeEntryController::class, 'store']);
Route::get('manual-time-entries/{timeLog}', [ManualTimeEntryController::class, 'show']);
Route::patch('manual-time-entries/{timeLog}', [ManualTimeEntryController::class, 'update']);

// ============================================
// LATE ADJUSTMENTS
// ============================================

Route::get('late-adjustments/eligible-logs', [LateAdjustmentController::class, 'eligibleLogs']);
Route::get('late-adjustments', [LateAdjustmentController::class, 'index']);
Route::post('late-adjustments', [LateAdjustmentController::class, 'store']);
Route::get('late-adjustments/{lateAdjustment}', [LateAdjustmentController::class, 'show']);
Route::match(['put', 'patch'], 'late-adjustments/{lateAdjustment}', [LateAdjustmentController::class, 'update']);
Route::patch('late-adjustments/{lateAdjustment}/approve', [LateAdjustmentController::class, 'approve']);
Route::patch('late-adjustments/{lateAdjustment}/reject', [LateAdjustmentController::class, 'reject']);

// ============================================
// OVERTIME REQUESTS
// ============================================

Route::get('overtime-requests', [OvertimeController::class, 'index']);
Route::post('overtime-requests', [OvertimeController::class, 'store']);
Route::patch('overtime-requests/{overtimeRequest}/approve', [OvertimeController::class, 'approve']);
Route::patch('overtime-requests/{overtimeRequest}/reject', [OvertimeController::class, 'reject']);
Route::patch('overtime-requests/{overtimeRequest}/revert', [OvertimeController::class, 'revert']);

// ============================================
// UNDERTIME REQUESTS
// ============================================

Route::get('undertime-requests', [UndertimeController::class, 'index']);
Route::post('undertime-requests', [UndertimeController::class, 'store']);
Route::patch('undertime-requests/{undertimeRequest}/approve', [UndertimeController::class, 'approve']);
Route::patch('undertime-requests/{undertimeRequest}/reject', [UndertimeController::class, 'reject']);
Route::patch('undertime-requests/{undertimeRequest}/revert', [UndertimeController::class, 'revert']);



// ============================================
// LEAVE: TYPES, POLICIES & APPROVALS
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
// LEAVE CREDITS
// ============================================

Route::get('leave-credit-transactions', [LeaveCreditTransactionController::class, 'index']);
Route::post('leave-credit-transactions/adjust', [LeaveCreditTransactionController::class, 'adjust']);
Route::get('leave-credit-balances', [LeaveCreditTransactionController::class, 'balances']);

// ============================================
// SALARY RATES & TYPES
// ============================================

Route::get('salary-types', [SalaryTypeController::class, 'index']);

Route::get('employee-salary-rates/filter-options', [EmployeeSalaryRateController::class, 'filterOptions']);
Route::get('employee-salary-rates', [EmployeeSalaryRateController::class, 'index']);
Route::get('employee-salary-rates/{salarySetup}', [EmployeeSalaryRateController::class, 'show']);

// ============================================
// SALARY ADJUSTMENTS (requires permission)
// ============================================

Route::middleware('can:manage-salary-adjustments')->group(function () {
    Route::get('salary-adjustments', [SalaryAdjustmentController::class, 'index']);
    Route::post('salary-adjustments', [SalaryAdjustmentController::class, 'store']);
    Route::get('salary-adjustments/{salaryAdjustment}', [SalaryAdjustmentController::class, 'show']);
    Route::patch('salary-adjustments/{salaryAdjustment}/approve', [SalaryAdjustmentController::class, 'approve']);
    Route::patch('salary-adjustments/{salaryAdjustment}/reject', [SalaryAdjustmentController::class, 'reject']);
    Route::patch('salary-adjustments/{salaryAdjustment}/apply', [SalaryAdjustmentController::class, 'apply']);
});

// ============================================
// CONTRIBUTIONS
// ============================================

Route::apiResource('contribution-types', ContributionTypeController::class);
Route::apiResource('contribution-rate-schedules', ContributionRateScheduleController::class);
Route::apiResource('contribution-rate-brackets', ContributionRateBracketController::class);
Route::apiResource('contribution-deduction-settings', ContributionDeductionSettingController::class);
Route::get('contribution-change-logs', [ContributionChangeLogController::class, 'index']);
Route::get('contribution-change-logs/{contributionChangeLog}', [ContributionChangeLogController::class, 'show']);

// ============================================
// LOAN TYPES
// ============================================

Route::get('loan-types', [LoanTypesController::class, 'index']);
Route::post('loan-types', [LoanTypesController::class, 'store']);
Route::get('loan-types/{loanType}/edit', [LoanTypesController::class, 'edit']);
Route::match(['put', 'patch'], 'loan-types/{loanType}', [LoanTypesController::class, 'update']);
Route::delete('loan-types/{loanType}', [LoanTypesController::class, 'destroy']);

// ============================================
// EMPLOYEE LOANS
// ============================================

Route::get('employee-loans', [EmployeeLoanController::class, 'index']);
Route::post('employee-loans', [EmployeeLoanController::class, 'store']);
Route::get('employee-loans/{employeeLoan}', [EmployeeLoanController::class, 'show']);
Route::put('employee-loans/{employeeLoan}', [EmployeeLoanController::class, 'update']);
Route::get('employee-loans/{employeeLoan}/transactions', [EmployeeLoanTransactionController::class, 'history']);
Route::get('employee-loan-transactions', [EmployeeLoanTransactionController::class, 'index']);

// ============================================
// PAYROLL GROUPS
// ============================================

Route::prefix('payroll-groups')->group(function () {
    Route::get('/', [PayrollGroupController::class, 'index']);
    Route::post('/', [PayrollGroupController::class, 'store']);

    // Must stay above /{payrollGroup}
    Route::get('/employee-filters', [PayrollGroupController::class, 'employeeFilters']);
    Route::get('/employees', [PayrollGroupController::class, 'employees']);

    Route::get('/{payrollGroup}', [PayrollGroupController::class, 'show']);
    Route::match(['put', 'patch'], '/{payrollGroup}', [PayrollGroupController::class, 'update']);
    Route::delete('/{payrollGroup}', [PayrollGroupController::class, 'destroy']);

    Route::get('/{payrollGroup}/assignments', [PayrollGroupController::class, 'assignments']);
    Route::post('/{payrollGroup}/assignments', [PayrollGroupController::class, 'assignEmployee']);
    Route::post('/{payrollGroup}/assignments/bulk', [PayrollGroupController::class, 'bulkAssign']);
    Route::patch('/{payrollGroup}/assignments/{assignment}/end', [PayrollGroupController::class, 'endAssignment']);
    Route::delete('/{payrollGroup}/assignments/{assignment}', [PayrollGroupController::class, 'undoAssignment']);
});

// ============================================
// PAYROLL PERIODS, READINESS & GENERATION
// ============================================

Route::apiResource('payroll-periods', PayrollPeriodController::class);
Route::patch('payroll-periods/{payrollPeriod}/status', [PayrollPeriodController::class, 'updateStatus']);

Route::get('payroll-readiness', [PayrollReadinessController::class, 'index']);

Route::get('payroll-generation/{payrollPeriod}', [PayrollGenerationController::class, 'index']);
Route::post('payroll-generation/{payrollPeriod}/generate', [PayrollGenerationController::class, 'generate']);
Route::post('payroll-generation/{payrollPeriod}/finalize', [PayrollGenerationController::class, 'finalize']);




// ============================================
// QR GENERATION
// ============================================
Route::post('attendance-qr/generate', [QrCodeGenerationController::class, 'generate']);

Route::prefix('attendance-qr/sessions')->group(function () {
    Route::post('/', [GenerateQrCodeController::class, 'store']);
    Route::post('{session}/refresh', [GenerateQrCodeController::class, 'refresh']);
    Route::post('{session}/revoke', [GenerateQrCodeController::class, 'revoke']);
});

 
Route::get('payslips', [PayslipController::class, 'index']);
Route::get('payslips/{payroll}', [PayslipController::class, 'show']);

Route::get('employee-payrolls', [EmployeePayrollController::class, 'index']);


//holidays 
Route::get('holiday-pay-rules/types', [HolidayPayRuleController::class, 'types']);
Route::apiResource('holiday-pay-rules', HolidayPayRuleController::class);
Route::get('holiday-pays', [HolidayPayController::class, 'index']);
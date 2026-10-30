<?php

use App\Http\Controllers\Api\Admin\DepartmentController;
use App\Http\Controllers\Api\Admin\EmployeeController;
use App\Http\Controllers\Api\Admin\EmployeeLeaveBalanceController;
use App\Http\Controllers\Api\Admin\EmploymentDocumentsController;
use App\Http\Controllers\Api\Admin\EmploymentTypeController;
use App\Http\Controllers\Api\Admin\LeaveApprovalController;
use App\Http\Controllers\Api\Admin\LeavePoliciesController;
use App\Http\Controllers\Api\Admin\LeaveTypesController;
use App\Http\Controllers\Api\Admin\OvertimeController;
use App\Http\Controllers\Api\Admin\PayrollGroupController;
use App\Http\Controllers\Api\Admin\PayrollPeriodController;
use App\Http\Controllers\Api\Admin\PositionController;
use App\Http\Controllers\Api\Admin\RestDayController;
use App\Http\Controllers\Api\Admin\SalaryTypeController;
use App\Http\Controllers\Api\Admin\ScheduleController;
use App\Http\Controllers\Api\Admin\ScheduleTypeController;
use App\Http\Controllers\Api\Admin\SystemUserController;
use App\Http\Controllers\Api\Admin\UndertimeController;
use App\Http\Controllers\Api\Admin\LeaveCreditTransactionController;


use Illuminate\Support\Facades\Route;

Route::get('/employees', [EmployeeController::class, 'index']);
Route::post('/employees', [EmployeeController::class, 'store']);
Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
Route::match(['put', 'patch'], '/employees/{employee}', [EmployeeController::class, 'update']);
Route::patch('/employees/{employee}/deactivate', [EmployeeController::class, 'deactivate']);
Route::get('/system-users', [SystemUserController::class, 'index']);
Route::get('/system-users/employees/{employee}/edit', [SystemUserController::class, 'editEmployee']);
Route::post('/system-users/employees/{employee}/setup-account', [SystemUserController::class, 'setupAccount']);
Route::get('/system-users/{user}/edit', [SystemUserController::class, 'edit']);
Route::match(['put', 'patch'], '/system-users/{user}', [SystemUserController::class, 'update']);
Route::patch('/system-users/{user}/role', [SystemUserController::class, 'updateRole']);
Route::get('schedule-types', [ScheduleTypeController::class, 'index']);
Route::apiResource('employment-types', EmploymentTypeController::class);
Route::get('employment-documents', [EmploymentDocumentsController::class, 'index']);
Route::get('rest-days', [RestDayController::class, 'index']);
Route::apiResource('work-schedules', ScheduleController::class);

Route::apiResource('departments', DepartmentController::class);
Route::apiResource('positions', PositionController::class);

Route::get('salary-types', [SalaryTypeController::class, 'index']);
Route::get('payroll-groups', [PayrollGroupController::class, 'index']);
Route::apiResource('payroll-periods', PayrollPeriodController::class);

Route::apiResource('leave-types', LeaveTypesController::class);
Route::apiResource('leave-policies', LeavePoliciesController::class);

Route::get('leave-approvals', [LeaveApprovalController::class, 'index']);
Route::post('leave-approvals', [LeaveApprovalController::class, 'store']);
Route::patch('leave-approvals/{leaveRequest}/approve', [LeaveApprovalController::class, 'approve']);
Route::patch('leave-approvals/{leaveRequest}/reject', [LeaveApprovalController::class, 'reject']);
Route::patch('leave-approvals/{leaveRequest}/revert', [LeaveApprovalController::class, 'revert']);

Route::get('overtime-requests', [OvertimeController::class, 'index']);
Route::post('overtime-requests', [OvertimeController::class, 'store']);
Route::patch('overtime-requests/{overtimeRequest}/approve', [OvertimeController::class, 'approve']);
Route::patch('overtime-requests/{overtimeRequest}/reject', [OvertimeController::class, 'reject']);
Route::patch('overtime-requests/{overtimeRequest}/revert', [OvertimeController::class, 'revert']);

Route::get('undertime-requests', [UndertimeController::class, 'index']);
Route::post('undertime-requests', [UndertimeController::class, 'store']);
Route::patch('undertime-requests/{undertimeRequest}/approve', [UndertimeController::class, 'approve']);
Route::patch('undertime-requests/{undertimeRequest}/reject', [UndertimeController::class, 'reject']);
Route::patch('undertime-requests/{undertimeRequest}/revert', [UndertimeController::class, 'revert']);

Route::get('leave-credit-transactions', [LeaveCreditTransactionController::class, 'index']);
Route::get('leave-credit-balances', [LeaveCreditTransactionController::class, 'balances']);
Route::post('leave-credit-transactions/adjust', [LeaveCreditTransactionController::class, 'adjust']);

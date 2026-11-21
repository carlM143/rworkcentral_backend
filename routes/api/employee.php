<?php

use App\Http\Controllers\Api\Employee\EmployeeAttendanceController;
use App\Http\Controllers\Api\Employee\EmployeeLeaveCreditController;
use App\Http\Controllers\Api\Employee\EmployeeCalendarController;
use App\Http\Controllers\Api\Employee\EmployeeLeaveTypesController;
use App\Http\Controllers\Api\Employee\EmployeePayslipController;
use App\Http\Controllers\Api\Employee\EmployeeProfileController;
use App\Http\Controllers\Api\Employee\LeaveRequestController;
use App\Http\Controllers\Api\Employee\LoanController;
use App\Http\Controllers\Api\Employee\OvertimeRequestController;
use App\Http\Controllers\Api\Employee\TimeLogController;
use App\Http\Controllers\Api\Employee\UndertimeRequestController;
use App\Http\Controllers\Api\Employee\TimeLogCorrectionRequestController;
use App\Http\Controllers\Api\Employee\ObaRequestcontroller;
use App\Http\Controllers\Api\Employee\ObaDepartmentHeadController;

use Illuminate\Support\Facades\Route;

// Attendance (QR scan: first scan = time in, next scan = time out)
Route::post('/attendance/scan', [EmployeeAttendanceController::class, 'scan']);

// Profile & Account Settings
Route::get('/profile', [EmployeeProfileController::class, 'show']);
Route::get('/calendar', [EmployeeCalendarController::class, 'index']);
Route::get('/profile/photo', [EmployeeProfileController::class, 'getPhoto']);
Route::post('/profile/photo', [EmployeeProfileController::class, 'uploadPhoto']);
Route::delete('/profile/photo', [EmployeeProfileController::class, 'deletePhoto']);
Route::match(['put', 'patch'], '/profile/contact', [EmployeeProfileController::class, 'updateContact']);
Route::post('/profile/password', [EmployeeProfileController::class, 'updatePassword']);

Route::get('/leave-credits', [EmployeeLeaveCreditController::class, 'index']);

Route::get('/leave-types', [EmployeeLeaveTypesController::class, 'index']);

// Requests
Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
Route::post('/leave-requests/preview', [LeaveRequestController::class, 'preview']);
Route::post('/leave-requests', [LeaveRequestController::class, 'store']);
Route::match(['put', 'patch'], '/leave-requests/{leaveRequest}', [LeaveRequestController::class, 'update']);
Route::patch('/leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);

// Overtime Requests
Route::get('/overtime-requests', [OvertimeRequestController::class, 'index']);
Route::post('/overtime-requests', [OvertimeRequestController::class, 'store']);
Route::match(['put', 'patch'], '/overtime-requests/{overtimeRequest}', [OvertimeRequestController::class, 'update']);
Route::patch('/overtime-requests/{overtimeRequest}/cancel', [OvertimeRequestController::class, 'cancel']);

// Undertime Requests
Route::get('/undertime-requests', [UndertimeRequestController::class, 'index']);
Route::post('/undertime-requests', [UndertimeRequestController::class, 'store']);
Route::match(['put', 'patch'], '/undertime-requests/{undertimeRequest}', [UndertimeRequestController::class, 'update']);
Route::patch('/undertime-requests/{undertimeRequest}/cancel', [UndertimeRequestController::class, 'cancel']);

// Attendance & Time Logs
Route::get('/time-logs', [TimeLogController::class, 'index']);
Route::get('/time-logs/today', [TimeLogController::class, 'today']);

// Payslips / Payroll
Route::get('/payslips', [EmployeePayslipController::class, 'index']);
Route::get('/payslips/{payroll}', [EmployeePayslipController::class, 'show']);

// Loans (read-only: the employee's own loans and payment history)
Route::get('/loans', [LoanController::class, 'index']);
Route::get('/loans/{employeeLoan}', [LoanController::class, 'show']);

// Time Log Correction Requests
Route::get('/time-log-correction-requests/time-log', [TimeLogCorrectionRequestController::class, 'timeLogByDate']);
Route::get('/time-log-correction-requests', [TimeLogCorrectionRequestController::class, 'index']);
Route::post('/time-log-correction-requests', [TimeLogCorrectionRequestController::class, 'store']);
Route::get('/time-log-correction-requests/{id}', [TimeLogCorrectionRequestController::class, 'show']);
Route::match(['put', 'patch'], '/time-log-correction-requests/{id}', [TimeLogCorrectionRequestController::class, 'update']);
Route::patch('/time-log-correction-requests/{id}/cancel', [TimeLogCorrectionRequestController::class, 'cancel']);


Route::get('oba-requests', [ObaRequestcontroller::class, 'index']);
Route::post('oba-requests', [ObaRequestcontroller::class, 'store']);

// Static routes first
Route::get('oba-requests/departments', [ObaRequestcontroller::class, 'departments']);
Route::get('oba-requests/available-employees', [ObaRequestcontroller::class, 'availableEmployees']);
Route::get('oba-requests/approvals', [ObaDepartmentHeadController::class, 'index']);

Route::get('oba-requests/{obaRequest}', [ObaRequestcontroller::class, 'show']);
Route::patch('oba-requests/{obaRequest}/cancel', [ObaRequestcontroller::class, 'cancel']);
Route::patch('oba-requests/{obaRequest}/dept-head/approve', [ObaDepartmentHeadController::class, 'approve']);
Route::patch('oba-requests/{obaRequest}/dept-head/reject', [ObaDepartmentHeadController::class, 'reject']);
Route::post('oba-requests/{obaRequest}/employees', [ObaRequestcontroller::class, 'addEmployees']);
Route::delete('oba-requests/{obaRequest}/employees/{obaEmployee}', [ObaRequestcontroller::class, 'removeEmployee']);
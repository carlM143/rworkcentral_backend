<?php

use App\Http\Controllers\Api\Employee\EmployeePayslipController;
use App\Http\Controllers\Api\Employee\EmployeeProfileController;
use App\Http\Controllers\Api\Employee\LeaveRequestController;
use App\Http\Controllers\Api\Employee\OvertimeRequestController;
use App\Http\Controllers\Api\Employee\TimeLogController;
use App\Http\Controllers\Api\Employee\UndertimeRequestController;
use Illuminate\Support\Facades\Route;

// Profile & Account Settings
Route::get('/profile', [EmployeeProfileController::class, 'show']);
Route::get('/profile/photo', [EmployeeProfileController::class, 'getPhoto']);
Route::post('/profile/photo', [EmployeeProfileController::class, 'uploadPhoto']);
Route::delete('/profile/photo', [EmployeeProfileController::class, 'deletePhoto']);
Route::match(['put', 'patch'], '/profile/contact', [EmployeeProfileController::class, 'updateContact']);
Route::post('/profile/password', [EmployeeProfileController::class, 'updatePassword']);

// Leave Types & Leave Requests
Route::get('/leave-types', [LeaveRequestController::class, 'types']);
Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
Route::post('/leave-requests', [LeaveRequestController::class, 'store']);
Route::patch('/leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);

// Overtime Requests
Route::get('/overtime-requests', [OvertimeRequestController::class, 'index']);
Route::post('/overtime-requests', [OvertimeRequestController::class, 'store']);
Route::patch('/overtime-requests/{overtimeRequest}/cancel', [OvertimeRequestController::class, 'cancel']);

// Undertime Requests
Route::get('/undertime-requests', [UndertimeRequestController::class, 'index']);
Route::post('/undertime-requests', [UndertimeRequestController::class, 'store']);
Route::patch('/undertime-requests/{undertimeRequest}/cancel', [UndertimeRequestController::class, 'cancel']);

// Attendance & Time Logs
Route::get('/time-logs', [TimeLogController::class, 'index']);
Route::get('/time-logs/today', [TimeLogController::class, 'today']);

// Payslips / Payroll
Route::get('/payslips', [EmployeePayslipController::class, 'index']);
Route::get('/payslips/{payroll}', [EmployeePayslipController::class, 'show']);

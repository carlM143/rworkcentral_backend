<?php

use App\Http\Controllers\Api\Admin\DepartmentController;
use App\Http\Controllers\Api\Admin\EmployeeController;
use App\Http\Controllers\Api\Admin\EmploymentTypeController;
use App\Http\Controllers\Api\Admin\PayrollGroupController;
use App\Http\Controllers\Api\Admin\PositionController;
use App\Http\Controllers\Api\Admin\RestDayController;
use App\Http\Controllers\Api\Admin\SalaryTypeController;
use App\Http\Controllers\Api\Admin\ScheduleController;
use App\Http\Controllers\Api\Admin\ScheduleTypeController;
use App\Http\Controllers\Api\Admin\PayrollPeriodController;


use Illuminate\Support\Facades\Route;

Route::get('/employees', [EmployeeController::class, 'index']);
Route::post('/employees', [EmployeeController::class, 'store']);
Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
Route::match(['put', 'patch'], '/employees/{employee}', [EmployeeController::class, 'update']);
Route::patch('/employees/{employee}/deactivate', [EmployeeController::class, 'deactivate']);
Route::get('schedule-types', [ScheduleTypeController::class, 'index']);
Route::get('employment-types', [EmploymentTypeController::class, 'index']);
Route::get('rest-days', [RestDayController::class, 'index']);
Route::apiResource('work-schedules', ScheduleController::class);

Route::apiResource('departments', DepartmentController::class);
Route::apiResource('positions', PositionController::class);

Route::get('salary-types', [SalaryTypeController::class, 'index']);
Route::get('payroll-groups', [PayrollGroupController::class, 'index']);
Route::apiResource('payroll-periods', PayrollPeriodController::class);
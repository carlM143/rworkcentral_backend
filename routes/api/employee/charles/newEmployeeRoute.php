<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Employee\EmployeeWfhRequestController;

Route::get('wfh-requests', [EmployeeWfhRequestController::class, 'index']);
Route::get('wfh-requests/schedule', [EmployeeWfhRequestController::class, 'schedule']);
Route::get('wfh-requests/today', [EmployeeWfhRequestController::class, 'today']);
Route::post('wfh-requests/time-in', [EmployeeWfhRequestController::class, 'timeIn']);
Route::post('wfh-requests/time-out', [EmployeeWfhRequestController::class, 'timeOut']);
Route::post('wfh-requests/preview', [EmployeeWfhRequestController::class, 'preview']);
Route::post('wfh-requests', [EmployeeWfhRequestController::class, 'store']);
Route::post('wfh-requests/{workFromHome}/days', [EmployeeWfhRequestController::class, 'attachDay']);

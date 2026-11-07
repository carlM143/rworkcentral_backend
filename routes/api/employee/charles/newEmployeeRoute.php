<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Employee\EmployeeWfhRequestController;

Route::get('wfh-requests', [EmployeeWfhRequestController::class, 'index']);
Route::post('wfh-requests/preview', [EmployeeWfhRequestController::class, 'preview']);
Route::post('wfh-requests', [EmployeeWfhRequestController::class, 'store']);

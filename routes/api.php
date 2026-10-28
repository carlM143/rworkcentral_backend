<?php

use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'Laravel API is connected!'
    ]);
});

require __DIR__.'/api/auth.php';

Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    require __DIR__.'/api/admin.php';
});

Route::prefix('employee')->middleware('auth:sanctum')->group(function () {
    require __DIR__.'/api/employee.php';
});
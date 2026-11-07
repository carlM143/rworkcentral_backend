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

    // files directly in api/admin/
    foreach (glob(__DIR__.'/api/admin/*.php') ?: [] as $file) {
        require $file;
    }

    // files in api/admin/carl/, api/admin/charles/, etc.
    foreach (glob(__DIR__.'/api/admin/*/*.php') ?: [] as $file) {
        require $file;
    }
});

Route::prefix('employee')->middleware('auth:sanctum')->group(function () {
    require __DIR__.'/api/employee.php';

    foreach (glob(__DIR__.'/api/employee/*.php') ?: [] as $file) {
        require $file;
    }

    foreach (glob(__DIR__.'/api/employee/*/*.php') ?: [] as $file) {
        require $file;
    }
});

// Route::get('/ping', function () {
//     return response()->json([
//         'success' => true,
//         'message' => 'Laravel API is connected!'
//     ]);
// });

// require __DIR__.'/api/auth.php';

// Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
//     require __DIR__.'/api/admin.php';
// });

// Route::prefix('employee')->middleware('auth:sanctum')->group(function () {
//     require __DIR__.'/api/employee.php';
// });
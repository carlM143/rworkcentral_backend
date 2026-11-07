<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\Admin\WfhDocumentController;
use App\Http\Controllers\Api\Admin\WfhRequestController;


// ============================================
// WORK FROM HOME: REQUESTS
// ============================================

Route::get('wfh-requests/employees', [WfhRequestController::class, 'employees']);
Route::post('wfh-requests/preview', [WfhRequestController::class, 'preview']);
Route::get('wfh-requests', [WfhRequestController::class, 'index']);
Route::post('wfh-requests', [WfhRequestController::class, 'store']);
Route::patch('wfh-requests/{workFromHome}/approve', [WfhRequestController::class, 'approve']);
Route::patch('wfh-requests/{workFromHome}/reject', [WfhRequestController::class, 'reject']);

// ============================================
// WORK FROM HOME: DOCUMENT REVIEW
// ============================================

Route::get('wfh-documents', [WfhDocumentController::class, 'index']);
Route::get('wfh-documents/{workFromHome}', [WfhDocumentController::class, 'show']);
Route::post('wfh-documents/{workFromHome}/days', [WfhDocumentController::class, 'attach']);
Route::patch('wfh-trackers/{wfhTracker}/accept', [WfhDocumentController::class, 'accept']);
Route::patch('wfh-trackers/{wfhTracker}/return', [WfhDocumentController::class, 'returnDay']);
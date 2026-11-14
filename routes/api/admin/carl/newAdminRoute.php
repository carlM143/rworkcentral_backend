<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\Admin\DriverController;
use App\Http\Controllers\Api\Admin\TripTicketController;
use App\Http\Controllers\Api\Admin\VehicleController;
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

// ============================================
// DRIVERS
// ============================================

Route::get('drivers/availability', [DriverController::class, 'availability']);
Route::get('drivers/eligible-employees', [DriverController::class, 'eligibleEmployees']);
Route::get('drivers/next-code', [DriverController::class, 'nextCode']);
Route::post('drivers', [DriverController::class, 'store']);
Route::get('drivers/{driverInfo}', [DriverController::class, 'show']);
Route::post('drivers/{driverInfo}', [DriverController::class, 'update']);
Route::patch('drivers/{driverInfo}/deactivate', [DriverController::class, 'deactivate']);
Route::patch('drivers/{driverInfo}/activate', [DriverController::class, 'activate']);

// ============================================
// TRIP TICKETS
// ============================================

Route::get('trip-tickets/departments', [TripTicketController::class, 'departments']);
Route::get('trip-tickets/requesters', [TripTicketController::class, 'requesters']);
Route::get('trip-tickets/options', [TripTicketController::class, 'options']);
Route::get('trip-tickets', [TripTicketController::class, 'index']);
Route::post('trip-tickets', [TripTicketController::class, 'store']);
Route::get('trip-tickets/{tripTicket}', [TripTicketController::class, 'show']);
Route::put('trip-tickets/{tripTicket}', [TripTicketController::class, 'update']);
Route::patch('trip-tickets/{tripTicket}/depart', [TripTicketController::class, 'depart']);
Route::patch('trip-tickets/{tripTicket}/return', [TripTicketController::class, 'returnTrip']);
Route::patch('trip-tickets/{tripTicket}/cancel', [TripTicketController::class, 'cancel']);

// ============================================
// VEHICLES
// ============================================

Route::get('vehicles', [VehicleController::class, 'index']);
Route::post('vehicles', [VehicleController::class, 'store']);
Route::put('vehicles/{vehicle}', [VehicleController::class, 'update']);
Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy']);
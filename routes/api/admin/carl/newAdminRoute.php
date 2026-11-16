<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\Admin\DriverController;
use App\Http\Controllers\Api\Admin\TripTicketController;
use App\Http\Controllers\Api\Admin\VehicleController;
use App\Http\Controllers\Api\Admin\WfhDocumentController;
use App\Http\Controllers\Api\Admin\WfhRequestController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\PendingRequestController;
use App\Http\Controllers\Api\Admin\UpcomingEventController;
use App\Http\Controllers\Api\Admin\DashboardPayrollController;
use App\Http\Controllers\Api\Admin\NotificationController;
use App\Http\Controllers\Api\Admin\AccountController;
use App\Http\Controllers\Api\Admin\WageOrderController;
use App\Http\Controllers\Api\Admin\RegionalRateController;

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

// ============================================
// DASHBOARD
// ============================================

Route::get('dashboard/summary', [DashboardController::class, 'summary']);
Route::get('dashboard/attendance', [DashboardController::class, 'attendance']);
Route::get('dashboard/headcount', [DashboardController::class, 'headcount']);

Route::get('dashboard/pending-requests', [PendingRequestController::class, 'summary']);
Route::get('pending-requests', [PendingRequestController::class, 'index']);
Route::get('dashboard/upcoming-events', [UpcomingEventController::class, 'index']);
Route::get('upcoming-events', [UpcomingEventController::class, 'index']);

Route::get('dashboard/payroll-status', [DashboardPayrollController::class, 'show']);    

// NOTIFICATIONS (signed-in user)
Route::get('notifications/summary', [NotificationController::class, 'summary']);
Route::get('notifications', [NotificationController::class, 'index']);
Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead']);
Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);

// ============================================
// MY ACCOUNT (signed-in user)
// ============================================

Route::get('account', [AccountController::class, 'show']);
Route::put('account', [AccountController::class, 'update']);
Route::put('account/password', [AccountController::class, 'updatePassword']);
Route::delete('account/sessions', [AccountController::class, 'destroySessions']);

// ============================================
// COMPENSATION: WAGE ORDERS
// ============================================

Route::get('wage-orders/meta', [WageOrderController::class, 'meta']);
Route::get('wage-orders', [WageOrderController::class, 'index']);
Route::post('wage-orders', [WageOrderController::class, 'store']);
Route::get('wage-orders/{wageOrder}', [WageOrderController::class, 'show']);
Route::put('wage-orders/{wageOrder}', [WageOrderController::class, 'update']);
Route::delete('wage-orders/{wageOrder}', [WageOrderController::class, 'destroy']);
Route::patch('wage-orders/{wageOrder}/publish', [WageOrderController::class, 'publish']);
Route::patch('wage-orders/{wageOrder}/cancel', [WageOrderController::class, 'cancel']);
Route::post('wage-orders/{wageOrder}/document', [WageOrderController::class, 'uploadDocument']);
Route::delete('wage-orders/{wageOrder}/document', [WageOrderController::class, 'deleteDocument']);

Route::get('regional-rates', [RegionalRateController::class, 'index']);
Route::patch('regional-rates/locations/{location}', [RegionalRateController::class, 'updateLocation']);
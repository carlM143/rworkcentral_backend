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
use App\Http\Controllers\Api\Admin\WageOrderEmployeeController;
use App\Http\Controllers\Api\Admin\WageAdjustmentController;
use App\Http\Controllers\Api\Admin\RegionalRateController;
use App\Http\Controllers\Api\Admin\SalaryAdjustmentController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\SupervisorApprovalController;
use App\Http\Controllers\Api\Admin\DepartmentHeadController;
use App\Http\Controllers\Api\Admin\WageComplianceController;
use App\Http\Controllers\Api\Admin\PayrollReportController;
use App\Http\Controllers\Api\Admin\ContributionReportController;

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
Route::get('trip-tickets/approvers', [TripTicketController::class, 'approvers']);
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

// ============================================
// NOTIFICATIONS (signed-in user)
// ============================================

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

// Apply a wage order to employees (creates pending wage adjustments)
Route::get('wage-orders/{wageOrder}/employees', [WageOrderEmployeeController::class, 'index']);
Route::post('wage-orders/{wageOrder}/apply', [WageOrderEmployeeController::class, 'apply']);

// ============================================
// COMPENSATION: REGIONAL RATES
// ============================================

Route::get('regional-rates', [RegionalRateController::class, 'index']);
Route::patch('regional-rates/locations/{location}', [RegionalRateController::class, 'updateLocation']);

// ============================================
// COMPENSATION: WAGE ADJUSTMENTS (from wage orders)
// EMPLOYEES: SALARY ADJUSTMENTS (manual)
// ============================================

Route::middleware('can:manage-salary-adjustments')->group(function () {
    // Wage Adjustments page: list + bulk approve / reject / apply
    Route::get('wage-adjustments', [WageAdjustmentController::class, 'index']);
    Route::patch('wage-adjustments/bulk', [WageAdjustmentController::class, 'bulk']);

    // Salary Adjustments page (manual changes)
    Route::get('salary-adjustments', [SalaryAdjustmentController::class, 'index']);
    Route::post('salary-adjustments', [SalaryAdjustmentController::class, 'store']);
    Route::get('salary-adjustments/{salaryAdjustment}', [SalaryAdjustmentController::class, 'show']);
    Route::put('salary-adjustments/{salaryAdjustment}', [SalaryAdjustmentController::class, 'update']);
    Route::delete('salary-adjustments/{salaryAdjustment}', [SalaryAdjustmentController::class, 'destroy']);
    Route::patch('salary-adjustments/{salaryAdjustment}/approve', [SalaryAdjustmentController::class, 'approve']);
    Route::patch('salary-adjustments/{salaryAdjustment}/reject', [SalaryAdjustmentController::class, 'reject']);
    Route::patch('salary-adjustments/{salaryAdjustment}/apply', [SalaryAdjustmentController::class, 'apply']);
});

// ============================================
// SETTINGS: ROLES & PERMISSIONS
// ============================================

Route::middleware('privilege:settings,roles.view')->group(function () {
    Route::get('roles', [RoleController::class, 'index']);
    Route::get('roles/catalog', [RoleController::class, 'catalog']);
    Route::get('roles/{role}', [RoleController::class, 'show']);
});
Route::post('roles', [RoleController::class, 'store'])->middleware('privilege:settings,roles.create');
Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('privilege:settings,roles.edit');
Route::put('roles/{role}/privileges', [RoleController::class, 'updatePrivileges'])->middleware('privilege:settings,roles.edit');
Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('privilege:settings,roles.delete');

// ============================================
// APPROVAL CENTER: FOR MY APPROVAL (supervisors)
// ============================================

Route::get('supervisor-approvals', [SupervisorApprovalController::class, 'index'])
    ->middleware('privilege:approvals,first_level.view');
Route::patch('supervisor-approvals/{type}/{id}/approve', [SupervisorApprovalController::class, 'approve'])
    ->middleware('privilege:approvals,first_level.approve')
    ->whereIn('type', ['leave', 'overtime', 'undertime', 'wfh', 'oba'])->whereNumber('id');
Route::patch('supervisor-approvals/{type}/{id}/reject', [SupervisorApprovalController::class, 'reject'])
    ->middleware('privilege:approvals,first_level.approve')
    ->whereIn('type', ['leave', 'overtime', 'undertime', 'wfh', 'oba'])->whereNumber('id');

// For HR's approval pages: supervisor names, and "Approve anyway"
Route::get('supervisor-approvals/approvers', [SupervisorApprovalController::class, 'approvers']);
Route::patch('supervisor-approvals/{type}/{id}/override', [SupervisorApprovalController::class, 'override'])
    ->whereIn('type', ['leave', 'overtime', 'undertime', 'wfh', 'oba'])->whereNumber('id');


// ============================================
// DEPARTMENTS: DEPARTMENT HEADS
// ============================================

Route::get('department-heads', [DepartmentHeadController::class, 'index']);
Route::patch('department-heads/{department}', [DepartmentHeadController::class, 'update']);    

// ============================================
// REPORTS: WAGE COMPLIANCE
// ============================================

Route::get('reports/wage-compliance', [WageComplianceController::class, 'index'])
    ->middleware('privilege:reports,wage_compliance.view');

// ============================================
// REPORTS: PAYROLL
// ============================================

Route::get('reports/payroll', [PayrollReportController::class, 'index'])
    ->middleware('privilege:reports,payroll.view');    

 // ============================================
// REPORTS: SSS, PAG-IBIG, PHILHEALTH
// ============================================

Route::get('reports/contributions/{agency}', [ContributionReportController::class, 'index'])
    ->middleware('privilege:reports,government.view')
    ->whereIn('agency', ['sss', 'philhealth', 'pagibig']);   
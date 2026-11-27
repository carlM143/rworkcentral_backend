<?php

use App\Http\Controllers\Api\Admin\EvaluationTemplateCategoryController;
use App\Http\Controllers\Api\Admin\EvaluationTemplateController;
use App\Http\Controllers\Api\Admin\DepartmentApproverController;
use App\Http\Controllers\Api\Admin\SupervisorApprovalController;
use App\Http\Controllers\Api\Admin\LifecycleConfigurationController;
use App\Http\Controllers\Api\Admin\LifecycleEvaluationDocumentController;
use App\Http\Controllers\Api\Admin\LifecycleWorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('reports/attendance', [\App\Http\Controllers\Api\Admin\AttendanceReportController::class, 'index']);

// Lifecycle policies and stages
Route::get('lifecycle/policies', [LifecycleConfigurationController::class, 'policies']);
Route::get('lifecycle/employment-types', [LifecycleConfigurationController::class, 'employmentTypes']);
Route::post('lifecycle/policies', [LifecycleConfigurationController::class, 'savePolicy']);
Route::put('lifecycle/policies/{policy}', [LifecycleConfigurationController::class, 'savePolicy']);
Route::delete('lifecycle/policies/{policy}', [LifecycleConfigurationController::class, 'deletePolicy']);
Route::get('lifecycle/stages', [LifecycleConfigurationController::class, 'stages']);
Route::post('lifecycle/stages', [LifecycleConfigurationController::class, 'saveStage']);
Route::put('lifecycle/stages/{stage}', [LifecycleConfigurationController::class, 'saveStage']);
Route::delete('lifecycle/stages/{stage}', [LifecycleConfigurationController::class, 'deleteStage']);

// Employee evaluation documents
Route::get('employees/{employee}/lifecycle-evaluations', [LifecycleEvaluationDocumentController::class, 'index']);
Route::post('employees/{employee}/lifecycle-evaluations', [LifecycleEvaluationDocumentController::class, 'store']);
Route::get('employees/{employee}/lifecycle-evaluations/{document}', [LifecycleEvaluationDocumentController::class, 'show']);

// Employee milestones, employment type changes and offboarding
Route::get('lifecycle/employees', [LifecycleWorkflowController::class, 'employees']);
Route::get('lifecycle/employees/{employee}/history', [LifecycleWorkflowController::class, 'employeeHistory']);
Route::get('lifecycle/employees/{employee}/employment-type-changes', [LifecycleWorkflowController::class, 'employmentTypeChanges']);
Route::post('lifecycle/employees/{employee}/employment-type-changes', [LifecycleWorkflowController::class, 'changeEmploymentType']);
Route::get('lifecycle/milestones', [LifecycleWorkflowController::class, 'milestones']);
Route::post('lifecycle/milestones', [LifecycleWorkflowController::class, 'createMilestone']);
Route::put('lifecycle/milestones/{milestone}', [LifecycleWorkflowController::class, 'saveMilestone']);
Route::get('lifecycle/offboarding', [LifecycleWorkflowController::class, 'offboardings']);
Route::post('lifecycle/offboarding', [LifecycleWorkflowController::class, 'saveOffboarding']);
Route::put('lifecycle/offboarding/{offboarding}', [LifecycleWorkflowController::class, 'saveOffboarding']);

// Evaluation Document Templates
Route::get('evaluation-templates', [EvaluationTemplateController::class, 'index']);
Route::post('evaluation-templates', [EvaluationTemplateController::class, 'store']);
Route::put('evaluation-templates/{template}', [EvaluationTemplateController::class, 'update']);
Route::get('evaluation-templates/{template}/download', [EvaluationTemplateController::class, 'download']);
Route::delete('evaluation-templates/{template}', [EvaluationTemplateController::class, 'destroy']);

// Evaluation Template Categories
Route::get('evaluation-template-categories', [EvaluationTemplateCategoryController::class, 'index']);
Route::post('evaluation-template-categories', [EvaluationTemplateCategoryController::class, 'store']);
Route::put('evaluation-template-categories/{category}', [EvaluationTemplateCategoryController::class, 'update']);
Route::delete('evaluation-template-categories/{category}', [EvaluationTemplateCategoryController::class, 'destroy']);

// Department request approvers
Route::get('departments/{department}/approvers', [DepartmentApproverController::class, 'index'])
    ->middleware('privilege:departments,request_approvers.view');
Route::put('departments/{department}/approvers', [DepartmentApproverController::class, 'update'])
    ->middleware('privilege:departments,request_approvers.edit');

// Requests assigned to department approvers
foreach (['leave' => 'leave-requests', 'overtime' => 'overtime-requests', 'undertime' => 'undertime-requests', 'oba' => 'oba-requests', 'wfh' => 'wfh-requests'] as $type => $resource) {
    Route::patch("{$resource}/{id}/department-approve", function (\Illuminate\Http\Request $request, int $id) use ($type) {
        return app(SupervisorApprovalController::class)->approve($request, $type, $id);
    })->whereNumber('id');
    Route::patch("{$resource}/{id}/department-reject", function (\Illuminate\Http\Request $request, int $id) use ($type) {
        return app(SupervisorApprovalController::class)->reject($request, $type, $id);
    })->whereNumber('id');
}
Route::get('supervisor-approvals', [SupervisorApprovalController::class, 'index']);
Route::patch('supervisor-approvals/{type}/{id}/approve', [SupervisorApprovalController::class, 'approve'])
    ->whereIn('type', ['leave', 'overtime', 'undertime', 'wfh', 'oba'])->whereNumber('id');
Route::patch('supervisor-approvals/{type}/{id}/reject', [SupervisorApprovalController::class, 'reject'])
    ->whereIn('type', ['leave', 'overtime', 'undertime', 'wfh', 'oba'])->whereNumber('id');

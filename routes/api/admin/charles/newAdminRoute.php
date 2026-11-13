<?php

use App\Http\Controllers\Api\Admin\LifecycleConfigurationController;
use App\Http\Controllers\Api\Admin\LifecycleEvaluationDocumentController;
use App\Http\Controllers\Api\Admin\LifecycleWorkflowController;
use Illuminate\Support\Facades\Route;

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

<?php

namespace App\Providers;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\UndertimeRequest;
use App\Models\User;
use App\Models\WorkFromHome;
use App\Observers\DepartmentHeadApprovalObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-salary-adjustments', function (User $user) {

            $role = $user->role;

            if (!$role) {
                return false;
            }

            $roleName = strtolower(trim((string) (
                $role->role_name
                ?? $role->name
                ?? $role->role_code
                ?? $role->code
                ?? ''
            )));

            return in_array($roleName, [
                'admin',
                'administrator',
                'super admin',
                'hr',
                'hr administrator',
                'human resources',
            ], true);
        });

        // Supervisor (first-level) approval of employee requests:
        // requests filed in the employee portal go to the supervisor first,
        // then HR gives the final approval.
        LeaveRequest::observe(DepartmentHeadApprovalObserver::class);
        OvertimeRequest::observe(DepartmentHeadApprovalObserver::class);
        UndertimeRequest::observe(DepartmentHeadApprovalObserver::class);
        WorkFromHome::observe(DepartmentHeadApprovalObserver::class);
    }
}

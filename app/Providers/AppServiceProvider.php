<?php

namespace App\Providers;
use App\Models\User;
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
            ], true);
        });

    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_lifecycle_stages', function (Blueprint $table) {
            $table->unsignedInteger('end_month_after_hire')->nullable();
            $table->boolean('is_managed_regularization')->default(false);
        });

        // Fresh installs never create these policy columns. Older databases
        // still need their configured window copied before the columns go away.
        if (!Schema::hasColumns('employee_lifecycle_policies', ['regularization_start_month', 'regularization_end_month'])) {
            return;
        }

        DB::table('employee_lifecycle_policies')->whereNotNull('regularization_start_month')
            ->eachById(function ($policy) {
                DB::table('employee_lifecycle_stages')->updateOrInsert(
                    ['lifecycle_policy_id' => $policy->id, 'stage_code' => 'M6_REGULARIZATION'],
                    fn ($exists) => [
                        'months_after_hire' => $policy->regularization_start_month,
                        'end_month_after_hire' => $policy->regularization_end_month,
                        'is_managed_regularization' => true,
                        ...($exists ? [] : [
                            'stage_name' => 'Probationary to Regular',
                            'sort_order' => 1,
                            'status' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]),
                    ],
                );
            });

        Schema::table('employee_lifecycle_policies', function (Blueprint $table) {
            $table->dropColumn(['regularization_start_month', 'regularization_end_month']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_lifecycle_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('regularization_start_month')->nullable();
            $table->unsignedSmallInteger('regularization_end_month')->nullable();
        });
        DB::table('employee_lifecycle_stages')->where('is_managed_regularization', true)
            ->eachById(function ($stage) {
                DB::table('employee_lifecycle_policies')->where('id', $stage->lifecycle_policy_id)->update([
                    'regularization_start_month' => $stage->months_after_hire,
                    'regularization_end_month' => $stage->end_month_after_hire,
                ]);
            });
        DB::table('employee_lifecycle_stages')->whereNotNull('end_month_after_hire')
            ->update(['months_after_hire' => DB::raw('end_month_after_hire')]);

        Schema::table('employee_lifecycle_stages', function (Blueprint $table) {
            $table->dropColumn(['end_month_after_hire', 'is_managed_regularization']);
        });
    }
};

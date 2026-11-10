<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_change_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('contribution_type_id');
            $table->unsignedBigInteger('contribution_rate_schedule_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();

            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');

            $table->enum('action', [
                'created',
                'updated',
                'deactivated',
                'versioned',
                'deleted',
            ]);

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->text('reason')->nullable();

            $table->unsignedBigInteger('changed_by');
            $table->string('actor_name', 255);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['contribution_type_id', 'created_at'], 'ccl_type_date_idx');
            $table->index('contribution_rate_schedule_id', 'ccl_schedule_idx');
            $table->index('company_id', 'ccl_company_idx');
            $table->index(['entity_type', 'entity_id'], 'ccl_entity_idx');
            $table->index('changed_by', 'ccl_changed_by_idx');

            $table->foreign('contribution_type_id', 'ccl_type_fk')
                ->references('id')
                ->on('contribution_types')
                ->restrictOnDelete();

            $table->foreign('contribution_rate_schedule_id', 'ccl_schedule_fk')
                ->references('id')
                ->on('contribution_rate_schedules')
                ->restrictOnDelete();

            $table->foreign('company_id', 'ccl_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete();

            $table->foreign('changed_by', 'ccl_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        // Backfill initial change log entries for existing contribution records if a user exists
        $defaultUser = DB::table('users')->orderBy('id')->first();
        if ($defaultUser) {
            $actorId = $defaultUser->id;
            $actorName = $defaultUser->name ?: ($defaultUser->email ?: 'System Administrator');
            $now = now();

            $types = DB::table('contribution_types')->get();
            foreach ($types as $type) {
                DB::table('contribution_change_logs')->insert([
                    'contribution_type_id' => $type->id,
                    'contribution_rate_schedule_id' => null,
                    'company_id' => null,
                    'entity_type' => 'contribution_type',
                    'entity_id' => $type->id,
                    'action' => 'created',
                    'old_values' => null,
                    'new_values' => json_encode([
                        'contribution_code' => $type->contribution_code,
                        'contribution_name' => $type->contribution_name,
                        'provider' => $type->provider,
                        'is_status' => (bool) $type->is_status,
                    ]),
                    'reason' => "Initial statutory contribution type configured ({$type->contribution_code}).",
                    'changed_by' => $actorId,
                    'actor_name' => $actorName,
                    'created_at' => $type->created_at ?? $now,
                ]);
            }

            $schedules = DB::table('contribution_rate_schedules')->get();
            foreach ($schedules as $schedule) {
                $bracketCount = DB::table('contribution_rate_brackets')
                    ->where('contribution_rate_schedule_id', $schedule->id)
                    ->count();

                DB::table('contribution_change_logs')->insert([
                    'contribution_type_id' => $schedule->contribution_type_id,
                    'contribution_rate_schedule_id' => $schedule->id,
                    'company_id' => null,
                    'entity_type' => 'rate_schedule',
                    'entity_id' => $schedule->id,
                    'action' => 'created',
                    'old_values' => null,
                    'new_values' => json_encode([
                        'calculation_method' => $schedule->calculation_method,
                        'salary_basis' => $schedule->salary_basis,
                        'employee_rate' => $schedule->employee_rate,
                        'employer_rate' => $schedule->employer_rate,
                        'employee_amount' => $schedule->employee_amount ?? null,
                        'employer_amount' => $schedule->employer_amount ?? null,
                        'frequency' => $schedule->frequency ?? null,
                        'minimum_salary_base' => $schedule->minimum_salary_base,
                        'maximum_salary_base' => $schedule->maximum_salary_base,
                        'effective_from' => $schedule->effective_from,
                        'effective_to' => $schedule->effective_to,
                        'brackets_count' => $bracketCount,
                        'is_status' => (bool) $schedule->is_status,
                    ]),
                    'reason' => "Initial {$schedule->calculation_method} rate schedule effective {$schedule->effective_from}" . ($bracketCount > 0 ? " with {$bracketCount} salary brackets." : '.'),
                    'changed_by' => $actorId,
                    'actor_name' => $actorName,
                    'created_at' => $schedule->created_at ?? $now,
                ]);
            }

            $settings = DB::table('contribution_deduction_settings')->get();
            foreach ($settings as $setting) {
                DB::table('contribution_change_logs')->insert([
                    'contribution_type_id' => $setting->contribution_type_id,
                    'contribution_rate_schedule_id' => null,
                    'company_id' => $setting->company_id,
                    'entity_type' => 'deduction_setting',
                    'entity_id' => $setting->id,
                    'action' => 'created',
                    'old_values' => null,
                    'new_values' => json_encode([
                        'company_id' => $setting->company_id,
                        'department_id' => $setting->department_id ?? null,
                        'salary_type_id' => $setting->salary_type_id ?? null,
                        'deduction_schedule' => $setting->deduction_schedule,
                        'first_cutoff_percent' => $setting->first_cutoff_percent,
                        'second_cutoff_percent' => $setting->second_cutoff_percent,
                        'week_1_percent' => $setting->week_1_percent ?? null,
                        'week_2_percent' => $setting->week_2_percent ?? null,
                        'week_3_percent' => $setting->week_3_percent ?? null,
                        'week_4_percent' => $setting->week_4_percent ?? null,
                        'effective_from' => $setting->effective_from,
                        'effective_to' => $setting->effective_to,
                        'is_status' => (bool) $setting->is_status,
                    ]),
                    'reason' => "Configured deduction setting ({$setting->deduction_schedule}) effective {$setting->effective_from}.",
                    'changed_by' => $actorId,
                    'actor_name' => $actorName,
                    'created_at' => $setting->created_at ?? $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_change_logs');
    }
};

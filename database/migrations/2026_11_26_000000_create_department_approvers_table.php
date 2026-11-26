<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['department_id', 'employee_id']);
        });

        // Preserve existing assignments; eligibility is checked by the workflow.
        $now = now();
        DB::table('departments')->whereNotNull('head_employee_id')
            ->select('id', 'head_employee_id')->orderBy('id')
            ->chunk(200, function ($departments) use ($now) {
                foreach ($departments as $department) {
                    DB::table('department_approvers')->insert([
                        'department_id' => $department->id,
                        'employee_id' => $department->head_employee_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_approvers');
    }
};

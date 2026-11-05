<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', fn (Blueprint $table) => $table->string('employee_coverage')->default('all'));
        Schema::create('holiday_employee_coverage', function (Blueprint $table) {
            $table->foreignId('holiday_id')->constrained('holidays')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->primary(['holiday_id', 'employee_id']);
        });
        DB::table('holiday_types')->whereIn('type_code', ['SPECIAL_WORKING', 'SPECIAL_NON_WORKING'])->update(['status' => false]);
        foreach (['SPECIAL' => 'Special Holiday', 'COMPANY' => 'Company Holiday'] as $code => $name) {
            $values = ['type_code' => $code, 'type_name' => $name, 'status' => true, 'created_at' => now(), 'updated_at' => now()];
            if (Schema::hasColumn('holiday_types', 'holiday_type_name')) {
                $values['holiday_type_name'] = $name;
                $values['user_id'] = DB::table('companies')->value('admin_id') ?? DB::table('users')->value('id');
            }
            DB::table('holiday_types')->insertOrIgnore($values);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Holiday coverage cannot be rolled back without discarding employee assignments.');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('holiday_types')->whereIn('type_code', ['WORKING_DAY', 'NON_WORKING_DAY'])->update(['status' => false]);
        $values = ['type_code' => 'LOCAL', 'type_name' => 'Local Holiday', 'status' => true, 'created_at' => now(), 'updated_at' => now()];
        if (Schema::hasColumn('holiday_types', 'holiday_type_name')) {
            $values['holiday_type_name'] = 'Local Holiday';
            $values['user_id'] = DB::table('companies')->value('admin_id') ?? DB::table('users')->value('id');
        }
        DB::table('holiday_types')->insertOrIgnore($values);
    }

    public function down(): void
    {
        DB::table('holiday_types')->whereIn('type_code', ['WORKING_DAY', 'NON_WORKING_DAY'])->update(['status' => true]);
        DB::table('holiday_types')->where('type_code', 'LOCAL')->update(['status' => false]);
    }
};

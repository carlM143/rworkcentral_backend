<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('holidays', 'applied_to') && DB::table('holidays')->where('applied_to', '!=', 'all')->exists()) {
            throw new RuntimeException('Resolve existing restricted holiday scopes before enabling the work calendar.');
        }
        if (! Schema::hasColumn('holidays', 'company_id')) {
            Schema::table('holidays', fn (Blueprint $table) => $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete());
            if (DB::table('companies')->count() === 1) {
                DB::table('holidays')->whereNull('company_id')->update(['company_id' => DB::table('companies')->value('id')]);
            }
        }
        if (! Schema::hasColumn('holidays', 'applies_to')) {
            Schema::table('holidays', fn (Blueprint $table) => $table->string('applies_to')->default('all_locations'));
        }
        if (! Schema::hasColumn('holidays', 'is_working_day')) {
            Schema::table('holidays', fn (Blueprint $table) => $table->boolean('is_working_day')->default(false));
        }
        if (! Schema::hasColumn('holiday_types', 'type_code')) {
            Schema::table('holiday_types', function (Blueprint $table) {
                $table->string('type_code')->nullable()->unique();
                $table->string('type_name')->nullable();
            });
            foreach (DB::table('holiday_types')->get() as $type) {
                DB::table('holiday_types')->where('id', $type->id)->update(['type_code' => 'LEGACY_'.$type->id, 'type_name' => $type->holiday_type_name]);
            }
        }
        foreach (['REGULAR' => 'Regular Holiday', 'SPECIAL_NON_WORKING' => 'Special Non-working Holiday', 'SPECIAL_WORKING' => 'Special Working Holiday', 'WORKING_DAY' => 'Working Day', 'NON_WORKING_DAY' => 'Non-working Day'] as $code => $name) {
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
        Schema::table('holidays', fn (Blueprint $table) => $table->dropColumn('is_working_day'));
    }
};

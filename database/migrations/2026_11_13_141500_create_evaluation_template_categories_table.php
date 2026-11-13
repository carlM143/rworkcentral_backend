<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_template_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            'Probationary Evaluation',
            'Regularization Form',
            'Annual Review',
            'Check-in Form',
            'Onboarding Checklist',
            'General',
        ];

        foreach ($defaults as $name) {
            DB::table('evaluation_template_categories')->insert([
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_template_categories');
    }
};

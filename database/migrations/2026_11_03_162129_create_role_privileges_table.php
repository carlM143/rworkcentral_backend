
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_privileges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            $table->foreignId('privilege_id')
                ->constrained('privileges')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['role_id', 'privilege_id'],
                'role_privileges_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_privileges');
    }
};

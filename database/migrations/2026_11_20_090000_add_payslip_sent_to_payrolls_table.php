<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            // When HR sent the payslip to the employee's account (null = not sent yet)
            $table->timestamp('payslip_sent_at')->nullable()->after('finalized_at');
            $table->foreignId('payslip_sent_by')->nullable()->after('payslip_sent_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['employee_id', 'payslip_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'payslip_sent_at']);
            $table->dropConstrainedForeignId('payslip_sent_by');
            $table->dropColumn('payslip_sent_at');
        });
    }
};

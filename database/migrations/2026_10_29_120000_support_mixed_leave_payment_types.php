<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE leave_requests MODIFY requested_pay_type ENUM('paid','unpaid','mixed') NOT NULL"
            );
            DB::statement(
                "ALTER TABLE leave_requests MODIFY approved_pay_type ENUM('paid','unpaid','mixed') NULL"
            );
            DB::statement(
                'ALTER TABLE leave_requests MODIFY leave_policy_id BIGINT UNSIGNED NULL'
            );
        }

        Schema::table('leave_request_dates', function (Blueprint $table): void {
            if (! Schema::hasColumn('leave_request_dates', 'requested_pay_type')) {
                $table->enum('requested_pay_type', ['paid', 'unpaid'])
                    ->nullable()
                    ->after('day_value');
            }

            if (! Schema::hasColumn('leave_request_dates', 'approved_pay_type')) {
                $table->enum('approved_pay_type', ['paid', 'unpaid'])
                    ->nullable()
                    ->after('requested_pay_type');
            }
        });

        DB::table('leave_request_dates as dates')
            ->join('leave_requests as requests', 'requests.id', '=', 'dates.leave_request_id')
            ->select([
                'dates.id as date_id',
                'dates.requested_pay_type as date_requested_pay_type',
                'dates.approved_pay_type as date_approved_pay_type',
                'requests.requested_pay_type as request_requested_pay_type',
                'requests.approved_pay_type as request_approved_pay_type',
            ])
            ->orderBy('dates.id')
            ->get()
            ->each(function ($row): void {
                $updates = [];

                if ($row->date_requested_pay_type === null
                    && in_array($row->request_requested_pay_type, ['paid', 'unpaid'], true)) {
                    $updates['requested_pay_type'] = $row->request_requested_pay_type;
                }

                if ($row->date_approved_pay_type === null
                    && in_array($row->request_approved_pay_type, ['paid', 'unpaid'], true)) {
                    $updates['approved_pay_type'] = $row->request_approved_pay_type;
                }

                if ($updates !== []) {
                    DB::table('leave_request_dates')
                        ->where('id', $row->date_id)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        $hasMixedRequests = DB::table('leave_requests')
            ->where('requested_pay_type', 'mixed')
            ->orWhere('approved_pay_type', 'mixed')
            ->exists();
        $hasPolicylessRequests = DB::table('leave_requests')
            ->whereNull('leave_policy_id')
            ->exists();

        if ($hasMixedRequests || $hasPolicylessRequests) {
            throw new RuntimeException(
                'Cannot safely roll back mixed leave support while mixed or policyless leave requests exist.'
            );
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE leave_requests MODIFY requested_pay_type ENUM('paid','unpaid') NOT NULL"
            );
            DB::statement(
                "ALTER TABLE leave_requests MODIFY approved_pay_type ENUM('paid','unpaid') NULL"
            );
            DB::statement(
                'ALTER TABLE leave_requests MODIFY leave_policy_id BIGINT UNSIGNED NOT NULL'
            );
        }

        Schema::table('leave_request_dates', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('leave_request_dates', 'requested_pay_type')
                    ? 'requested_pay_type'
                    : null,
                Schema::hasColumn('leave_request_dates', 'approved_pay_type')
                    ? 'approved_pay_type'
                    : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

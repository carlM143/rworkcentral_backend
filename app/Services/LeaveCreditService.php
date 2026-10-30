<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Employment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveCreditService
{
    public function currentEmployment(Employee $employee): ?Employment
    {
        return $employee->employments()
            ->orderByDesc('date_hired')
            ->orderByDesc('id')
            ->first();
    }

    public function resolvePaidPolicy(
        Employee $employee,
        int $leaveTypeId,
        string $firstDate,
        string $lastDate
    ): ?LeavePolicy {
        $employment = $this->currentEmployment($employee);

        if (! $employment) {
            return null;
        }

        return LeavePolicy::query()
            ->where('leave_type_id', $leaveTypeId)
            ->where('employment_type_id', $employment->employment_type_id)
            ->where('status', 'approved')
            ->where('is_eligible', true)
            ->where('is_paid', true)
            ->where(function ($query) use ($firstDate): void {
                $query->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $firstDate);
            })
            ->where(function ($query) use ($lastDate): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $lastDate);
            })
            ->orderByDesc('min_service_months')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->first(function (LeavePolicy $policy) use ($employment, $firstDate): bool {
                $qualifiedFrom = Carbon::parse($employment->date_hired)
                    ->addMonthsNoOverflow((int) $policy->min_service_months)
                    ->toDateString();

                return $firstDate >= $qualifiedFrom;
            });
    }

    public function syncAnnualCredits(
        Employee $employee,
        LeavePolicy $policy,
        int $year,
        int $createdBy
    ): void {
        if (! $policy->is_eligible || ! $policy->is_paid) {
            return;
        }

        $start = "{$year}-01-01";
        $end = "{$year}-12-31";

        $target = round((float) $policy->annual_credits, 2);

        if ($target <= 0) {
            return;
        }

        $prefix = "LEAVE-ENT-{$employee->id}-{$policy->leave_type_id}-{$policy->employment_type_id}-{$year}-";

        // Count existing policy-generated entitlements and
        // any previous annual allocation for the same benefit.
        // Exclude manual credit adjustments and carry-over.
        $alreadyGranted = (float) DB::table('leave_credit_transactions as t')
            ->join('leave_policies as p', 'p.id', '=', 't.leave_policy_id')
            ->where('t.employee_id', $employee->id)
            ->where('p.leave_type_id', $policy->leave_type_id)
            ->where('p.employment_type_id', $policy->employment_type_id)
            ->where('p.is_paid', true)
            ->where('t.cycle_start', $start)
            ->where('t.cycle_end', $end)
            ->where(function ($query) use ($prefix) {
                $query->where('t.transaction_type', 'allocation')
                    ->orWhere(function ($q) use ($prefix) {
                        $q->where('t.transaction_type', 'adjustment')
                            ->where('t.reference_key', 'like', $prefix.'%');
                    });
            })
            ->sum('t.days_delta');

        $difference = round($target - $alreadyGranted, 2);

        // Already allocated the entitlement or a higher one.
        if ($difference <= 0) {
            return;
        }

        $reference = $prefix.(int) round($target * 100);

        if (DB::table('leave_credit_transactions')
            ->where('reference_key', $reference)
            ->exists()
        ) {
            return;
        }

        DB::table('leave_credit_transactions')->insert([
            'employee_id' => $employee->id,
            'leave_policy_id' => $policy->id,
            'leave_request_id' => null,
            'transaction_type' => $alreadyGranted > 0
                ? 'adjustment'
                : 'allocation',
            'days_delta' => $difference,
            'cycle_start' => $start,
            'cycle_end' => $end,
            'effective_date' => now()->toDateString(),
            'reference_key' => $reference,
            'remarks' => $alreadyGranted > 0
                ? 'Automatic annual entitlement tier increase'
                : 'Automatic annual leave entitlement allocation',
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function availableBalance(
        Employee $employee,
        LeavePolicy $policy,
        int $year
    ): float {
        $balance = DB::table('leave_credit_transactions as t')
            ->join('leave_policies as p', 'p.id', '=', 't.leave_policy_id')
            ->where('t.employee_id', $employee->id)
            ->where('p.leave_type_id', $policy->leave_type_id)
            ->where('p.employment_type_id', $policy->employment_type_id)
            ->where('p.is_paid', true)
            ->where('t.cycle_start', "{$year}-01-01")
            ->where('t.cycle_end', "{$year}-12-31")
            ->sum('t.days_delta');

        return round((float) $balance, 2);
    }

    public function availableBalanceForSubmission(
        Employee $employee,
        LeavePolicy $policy,
        int $year
    ): float {
        $ledger = DB::table('leave_credit_transactions as t')
            ->join('leave_policies as p', 'p.id', '=', 't.leave_policy_id')
            ->where('t.employee_id', $employee->id)
            ->where('p.leave_type_id', $policy->leave_type_id)
            ->where('p.employment_type_id', $policy->employment_type_id)
            ->where('p.is_paid', true)
            ->where('t.cycle_start', "{$year}-01-01")
            ->where('t.cycle_end', "{$year}-12-31")
            ->selectRaw('COUNT(t.id) as transaction_count')
            ->selectRaw('COALESCE(SUM(t.days_delta), 0) as balance')
            ->first();

        if ((int) ($ledger->transaction_count ?? 0) === 0) {
            return round((float) $policy->annual_credits, 2);
        }

        return round((float) ($ledger->balance ?? 0), 2);
    }

    public function deductApprovedLeave(
        Employee $employee,
        LeavePolicy $policy,
        LeaveRequest $leaveRequest,
        float $paidDays,
        int $year,
        string $effectiveDate,
        int $createdBy
    ): void {
        $reference = "LEAVE-REQ-{$leaveRequest->id}-{$year}";

        if (DB::table('leave_credit_transactions')
            ->where('reference_key', $reference)
            ->exists()
        ) {
            return;
        }

        DB::table('leave_credit_transactions')->insert([
            'employee_id' => $employee->id,
            'leave_policy_id' => $policy->id,
            'leave_request_id' => $leaveRequest->id,
            'transaction_type' => 'deduction',
            'days_delta' => -round($paidDays, 2),
            'cycle_start' => "{$year}-01-01",
            'cycle_end' => "{$year}-12-31",
            'effective_date' => $effectiveDate,
            'reference_key' => $reference,
            'remarks' => "Approved paid leave {$leaveRequest->request_no}",
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

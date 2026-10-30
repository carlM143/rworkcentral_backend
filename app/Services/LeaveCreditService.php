<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Employment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveCreditService
{
    public function currentEmployment(Employee $employee): ?Employment
    {
        return $employee->employments()
            ->orderByDesc('date_hired')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Remove dates that fall on the employee's assigned non-working days.
     * The seven-day work schedule is authoritative; the legacy rest day is
     * used only when no matching schedule-day configuration is available.
     */
    public function filterWorkingDates(Employee $employee, array $dates): array
    {
        $employment = $this->currentEmployment($employee);

        if (! $employment) {
            return $dates;
        }

        $employment->loadMissing('workSchedule.days');

        if (! $employment->workSchedule) {
            return $dates;
        }

        $scheduleDays = $employment->workSchedule->days;

        return collect($dates)
            ->filter(function (array $date) use ($scheduleDays): bool {
                $weekday = $this->normalizeWeekday(
                    Carbon::parse($date['leave_date'])->format('l')
                );

                return $scheduleDays->contains(
                    fn ($day): bool => $this->normalizeWeekday($day->day_of_week) === $weekday
                );
            })
            ->values()
            ->all();
    }

    private function normalizeWeekday(string $day): string
    {
        return match (strtolower(trim($day))) {
            'saturday' => 'sat',
            'sunday' => 'sun',
            default => strtolower(trim($day)),
        };
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
            'transaction_type' => $alreadyGranted > 0 ? 'adjustment' : 'allocation',
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

    public function availableBalance(Employee $employee, LeavePolicy $policy, int $year): float
    {
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

    public function availableBalanceForSubmission(Employee $employee, LeavePolicy $policy, int $year): float
    {
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

    /**
     * A deduction is outstanding when it has no compensating UNDO entry.
     * Call only within a DB transaction that has locked the leave request
     * and employee rows (the controllers below do this).
     */
    private function outstandingDeductions(Employee $employee, LeaveRequest $leaveRequest): Collection
    {
        $deductions = DB::table('leave_credit_transactions')
            ->where('employee_id', $employee->id)
            ->where('leave_request_id', $leaveRequest->id)
            ->where('transaction_type', 'deduction')
            ->where('days_delta', '<', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $deductions->filter(function ($deduction) use ($leaveRequest): bool {
            $reference = $this->undoReference($leaveRequest, (int) $deduction->id);

            return ! DB::table('leave_credit_transactions')
                ->where('reference_key', $reference)
                ->exists();
        })->values();
    }

    private function undoReference(LeaveRequest $leaveRequest, int $deductionId): string
    {
        return "LEAVE-UNDO-{$leaveRequest->id}-{$deductionId}";
    }

    /**
     * Append a new deduction for each approval cycle. The original first
     * reference format is preserved for old records; re-approvals get -2, -3 ...
     */
    public function deductApprovedLeave(
        Employee $employee,
        LeavePolicy $policy,
        LeaveRequest $leaveRequest,
        float $paidDays,
        int $year,
        string $effectiveDate,
        int $createdBy
    ): void {
        if ($paidDays <= 0) {
            return;
        }

        if ($this->outstandingDeductions($employee, $leaveRequest)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'leave_credits' => 'An earlier approval has an un-reversed deduction. Undo it before approving again.',
            ]);
        }

        $previousDeductions = DB::table('leave_credit_transactions')
            ->where('employee_id', $employee->id)
            ->where('leave_request_id', $leaveRequest->id)
            ->where('transaction_type', 'deduction')
            ->count();

        $sequence = $previousDeductions + 1;
        $baseReference = "LEAVE-REQ-{$leaveRequest->id}-{$year}";
        $reference = $sequence === 1 ? $baseReference : "{$baseReference}-{$sequence}";

        if (DB::table('leave_credit_transactions')->where('reference_key', $reference)->exists()) {
            throw ValidationException::withMessages([
                'leave_credits' => 'Duplicate leave-credit reference. Approval was not completed.',
            ]);
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
            'remarks' => "Approved paid leave {$leaveRequest->request_no} (approval #{$sequence})",
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Restore each outstanding paid deduction to its ORIGINAL policy and
     * ORIGINAL annual credit cycle. Never delete or mutate ledger entries.
     * Must run in the same DB transaction as changing request status.
     * Returns number of days returned to the employee's balance.
     */
    public function restoreApprovedLeave(
        Employee $employee,
        LeaveRequest $leaveRequest,
        int $createdBy
    ): float {
        $outstanding = $this->outstandingDeductions($employee, $leaveRequest);

        if ($outstanding->isEmpty()
            && in_array($leaveRequest->approved_pay_type, ['paid', 'mixed'], true)) {
            throw ValidationException::withMessages([
                'leave_credits' => 'This approved paid leave has no outstanding deduction to restore. Verify the credit ledger before undoing.',
            ]);
        }

        $restored = 0.0;

        foreach ($outstanding as $deduction) {
            $days = round(- (float) $deduction->days_delta, 2);
            if ($days <= 0 || ! $deduction->leave_policy_id) {
                throw ValidationException::withMessages([
                    'leave_credits' => 'The original leave-credit deduction is invalid. Undo was cancelled.',
                ]);
            }

            DB::table('leave_credit_transactions')->insert([
                'employee_id' => $employee->id,
                'leave_policy_id' => $deduction->leave_policy_id,
                'leave_request_id' => $leaveRequest->id,
                'transaction_type' => 'adjustment',
                'days_delta' => $days,
                'cycle_start' => $deduction->cycle_start,
                'cycle_end' => $deduction->cycle_end,
                'effective_date' => now()->toDateString(),
                'reference_key' => $this->undoReference($leaveRequest, (int) $deduction->id),
                'remarks' => "Undo approval of leave {$leaveRequest->request_no}; restores deduction #{$deduction->id}",
                'created_by' => $createdBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $restored = round($restored + $days, 2);
        }

        return $restored;
    }
}

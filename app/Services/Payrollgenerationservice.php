<?php

namespace App\Services;

use App\Models\ContributionDeductionSetting;
use App\Models\ContributionRateSchedule;
use App\Models\ContributionType;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeeLoanTransaction;
use App\Models\EmployeePayrollGroupAssignment;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDate;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\PayrollContribution;
use App\Models\PayrollPeriod;
use App\Models\TimeLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PayrollGenerationService
{
    private const WORK_DAYS_PER_YEAR = 261;
    private const HOURS_PER_DAY = 8;
    private const OVERTIME_MULTIPLIER = 1.25;

    private const PERIODS_PER_MONTH = [
        'weekly' => 52 / 12,
        'bi_weekly' => 26 / 12,
        'semi_monthly' => 2,
        'monthly' => 1,
    ];

    public function generate(PayrollPeriod $period, ?int $groupId, ?int $userId): array
    {
        $start = $period->cutoff_start->format('Y-m-d');
        $end = $period->cutoff_end->format('Y-m-d');

        $assignments = EmployeePayrollGroupAssignment::query()
            ->with('payrollGroup:id,group_name,frequency')
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $start))
            ->orderBy('effective_from')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($history) => $history->last())
            ->filter(fn ($assignment) => $groupId === null
                || (int) $assignment->payroll_group_id === $groupId);

        $employeeIds = $assignments->keys()->values();

        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->with('salarySetup.salaryType')
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get();

        $timeLogs = TimeLog::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$start, $end])
            ->with('latestLateAdjustment')
            ->get()
            ->groupBy('employee_id');

        $overtime = OvertimeRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereBetween('ot_date', [$start, $end])
            ->get()
            ->groupBy('employee_id');

        $unpaidLeaveDays = $this->unpaidLeaveDays($employeeIds, $start, $end);

        $loans = EmployeeLoan::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', true)
            ->whereDate('deduction_start_date', '<=', $end)
            ->with('loanType')
            ->withSum(
                ['transactions as transactions_total' => fn ($q) => $q->where('status', true)],
                'amount_delta'
            )
            ->orderBy('deduction_start_date')
            ->get()
            ->groupBy('employee_id');

        $contributionTypes = ContributionType::query()
            ->where('is_status', true)
            ->orderBy('id')
            ->get();

        $settings = ContributionDeductionSetting::query()
            ->where('company_id', $period->company_id)
            ->where('is_status', true)
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $end))
            ->get()
            ->groupBy('contribution_type_id');

        $schedules = ContributionRateSchedule::query()
            ->where('is_status', true)
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $end))
            ->with('brackets')
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('contribution_type_id');

        $existing = Payroll::query()
            ->where('payroll_period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->get()
            ->keyBy('employee_id');

        $generated = [];
        $skipped = [];

        DB::transaction(function () use (
            $period,
            $userId,
            $assignments,
            $employees,
            $timeLogs,
            $overtime,
            $unpaidLeaveDays,
            $loans,
            $contributionTypes,
            $settings,
            $schedules,
            $existing,
            &$generated,
            &$skipped
        ) {
            foreach ($employees as $employee) {
                $label = trim("{$employee->lastname}, {$employee->firstname}");
                $salary = $employee->salarySetup;
                $current = $existing->get($employee->id);

                $skip = function (string $reason) use (&$skipped, $employee, $label) {
                    $skipped[] = [
                        'employee_id' => $employee->id,
                        'name' => $label,
                        'reason' => $reason,
                    ];
                };

                if ($current && $current->status === 'finalized') {
                    $skip('Payroll is already finalized.');
                    continue;
                }

                if (!$salary || (float) $salary->basic_salary <= 0) {
                    $skip('No salary setup or basic salary.');
                    continue;
                }

                if (!$salary->salary_type_id) {
                    $skip('Salary type is not set.');
                    continue;
                }

                $assignment = $assignments->get($employee->id);
                $frequency = $assignment->payrollGroup?->frequency ?? 'semi_monthly';
                $perMonth = self::PERIODS_PER_MONTH[$frequency] ?? 2;

                $rateType = $this->rateType($salary->salaryType?->type_name);
                $basic = (float) $salary->basic_salary;

                [$monthlyRate, $dailyRate] = match ($rateType) {
                    'daily' => [$basic * self::WORK_DAYS_PER_YEAR / 12, $basic],
                    'hourly' => [
                        $basic * self::HOURS_PER_DAY * self::WORK_DAYS_PER_YEAR / 12,
                        $basic * self::HOURS_PER_DAY,
                    ],
                    default => [$basic, $basic * 12 / self::WORK_DAYS_PER_YEAR],
                };

                $minuteRate = $dailyRate / self::HOURS_PER_DAY / 60;

                $logs = $timeLogs->get($employee->id, collect());
                $daysWorked = $logs->filter(fn ($log) => $log->time_in && $log->time_out)->count();
                $workedMinutes = (int) $logs->sum('worked_minutes');

                $basicPay = match ($rateType) {
                    'daily' => $daysWorked * $dailyRate,
                    'hourly' => $workedMinutes / 60 * $basic,
                    default => $monthlyRate / $perMonth,
                };

                $allowancePay = (float) $salary->allowance / $perMonth;

                $lateMinutes = (int) $logs->sum(fn ($log) => $this->lateMinutes($log));
                $undertimeMinutes = (int) $logs->sum(fn ($log) => $this->undertimeMinutes($log));
                $leaveDays = (float) ($unpaidLeaveDays[$employee->id] ?? 0);

                $deductsTime = $rateType !== 'hourly';

                $lateDeduction = $deductsTime ? $lateMinutes * $minuteRate : 0;
                $undertimeDeduction = $deductsTime ? $undertimeMinutes * $minuteRate : 0;
                $leaveDeduction = $rateType === 'monthly' ? $leaveDays * $dailyRate : 0;

                $overtimeMinutes = (int) $overtime->get($employee->id, collect())
                    ->sum(fn ($ot) => $ot->start_at && $ot->end_at
                        ? (int) abs($ot->start_at->diffInMinutes($ot->end_at))
                        : 0);

                $overtimePay = $overtimeMinutes * $minuteRate * self::OVERTIME_MULTIPLIER;

                $gross = max(0, $basicPay + $allowancePay + $overtimePay
                    - $lateDeduction - $undertimeDeduction - $leaveDeduction);

                $contributions = $this->contributions(
                    $period,
                    $employee,
                    $salary,
                    $monthlyRate,
                    $contributionTypes,
                    $settings,
                    $schedules
                );

                $contributionsTotal = collect($contributions)->sum('employee_share');

                $available = max(0, $gross - $contributionsTotal);
                $loanLines = [];

                foreach ($loans->get($employee->id, collect()) as $loan) {
                    $remaining = max(0, (float) $loan->opening_balance + (float) $loan->transactions_total);
                    $amount = min((float) $loan->deduction_amount, $remaining, $available);

                    if ($amount <= 0) {
                        continue;
                    }

                    $available -= $amount;

                    $loanLines[] = [
                        'employee_loan_id' => $loan->id,
                        'loan_type' => $loan->loanType?->loan_type_name
                            ?? $loan->loanType?->type_name
                            ?? $loan->loanType?->name
                            ?? 'Loan',
                        'reference' => $loan->loan_reference_no,
                        'amount' => round($amount, 2),
                        'balance_before' => round($remaining, 2),
                        'balance_after' => round($remaining - $amount, 2),
                        'partial' => $amount < (float) $loan->deduction_amount,
                    ];
                }

                $loanTotal = collect($loanLines)->sum('amount');
                $tax = 0;
                $totalDeductions = $contributionsTotal + $loanTotal + $tax;
                $net = $gross - $totalDeductions;

                $payroll = $current ?? new Payroll([
                    'employee_id' => $employee->id,
                    'payroll_period_id' => $period->id,
                ]);

                $payroll->fill([
                    'payroll_group_id' => $assignment->payroll_group_id,
                    'salary_type_id' => $salary->salary_type_id,
                    'basic_salary' => $basic,
                    'allowance' => (float) $salary->allowance,
                    'bank_name' => $salary->bank_name ?? '',
                    'account_name' => $salary->account_name ?? '',
                    'account_number' => $salary->account_number ?? '',
                    'status' => 'draft',
                    'daily_rate' => round($dailyRate, 2),
                    'days_worked' => $daysWorked,
                    'worked_minutes' => $workedMinutes,
                    'basic_pay' => round($basicPay, 2),
                    'allowance_pay' => round($allowancePay, 2),
                    'overtime_minutes' => $overtimeMinutes,
                    'overtime_pay' => round($overtimePay, 2),
                    'late_minutes' => $lateMinutes,
                    'late_deduction' => round($lateDeduction, 2),
                    'undertime_minutes' => $undertimeMinutes,
                    'undertime_deduction' => round($undertimeDeduction, 2),
                    'unpaid_leave_days' => $leaveDays,
                    'unpaid_leave_deduction' => round($leaveDeduction, 2),
                    'gross_pay' => round($gross, 2),
                    'contributions_total' => round($contributionsTotal, 2),
                    'loan_deductions_total' => round($loanTotal, 2),
                    'withholding_tax' => $tax,
                    'total_deductions' => round($totalDeductions, 2),
                    'net_pay' => round($net, 2),
                    'breakdown' => [
                        'rate_type' => $rateType,
                        'frequency' => $frequency,
                        'monthly_rate' => round($monthlyRate, 2),
                        'minute_rate' => round($minuteRate, 4),
                        'loans' => $loanLines,
                    ],
                    'generated_by' => $userId,
                    'generated_at' => now(),
                ])->save();

                $payroll->contributions()->delete();

                foreach ($contributions as $line) {
                    PayrollContribution::create([
                        'payroll_id' => $payroll->id,
                        'employee_id' => $employee->id,
                        'contribution_type_id' => $line['contribution_type_id'],
                        'contribution_rate_schedule_id' => $line['contribution_rate_schedule_id'],
                        'contribution_month' => $period->cutoff_end->copy()->startOfMonth()->toDateString(),
                        'salary_basis_amount' => $line['salary_basis_amount'],
                        'employee_share' => $line['employee_share'],
                        'employer_share' => $line['employer_share'],
                        'employer_ec_amount' => $line['employer_ec_amount'],
                        'reference_key' => "P{$payroll->id}-C{$line['contribution_type_id']}",
                    ]);
                }

                $generated[] = [
                    'payroll_id' => $payroll->id,
                    'employee_id' => $employee->id,
                    'name' => $label,
                    'gross_pay' => round($gross, 2),
                    'total_deductions' => round($totalDeductions, 2),
                    'net_pay' => round($net, 2),
                ];
            }
        });

        return [
            'generated' => $generated,
            'skipped' => $skipped,
        ];
    }

    public function finalize(PayrollPeriod $period, ?int $userId): array
    {
        $finalized = 0;
        $transactionDate = ($period->payment_date ?? $period->cutoff_end)->format('Y-m-d');

        DB::transaction(function () use ($period, $userId, $transactionDate, &$finalized) {
            $payrolls = Payroll::query()
                ->where('payroll_period_id', $period->id)
                ->where('status', 'draft')
                ->lockForUpdate()
                ->get();

            foreach ($payrolls as $payroll) {
                foreach ($payroll->breakdown['loans'] ?? [] as $line) {
                    EmployeeLoanTransaction::create([
                        'employee_loan_id' => $line['employee_loan_id'],
                        'payroll_id' => $payroll->id,
                        'transaction_type' => 'payroll_deduction',
                        'amount_delta' => -1 * (float) $line['amount'],
                        'transaction_date' => $transactionDate,
                        'reference_no' => "PR{$payroll->id}-L{$line['employee_loan_id']}",
                        'remarks' => 'Payroll deduction for ' . ($period->period_name ?? "period #{$period->id}"),
                        'created_by' => $userId,
                        'status' => true,
                    ]);

                    if ((float) $line['balance_after'] <= 0) {
                        EmployeeLoan::whereKey($line['employee_loan_id'])->update(['status' => false]);
                    }
                }

                $payroll->update([
                    'status' => 'finalized',
                    'finalized_by' => $userId,
                    'finalized_at' => now(),
                ]);

                $finalized++;
            }
        });

        return ['finalized' => $finalized];
    }

    private function rateType(?string $typeName): string
    {
        $name = strtolower((string) $typeName);

        if (str_contains($name, 'hour')) {
            return 'hourly';
        }

        if (str_contains($name, 'daily') || str_contains($name, 'day')) {
            return 'daily';
        }

        return 'monthly';
    }

    private function lateMinutes(TimeLog $log): int
    {
        $adjustment = $log->latestLateAdjustment;

        if (
            $adjustment
            && $adjustment->status === 'approved'
            && $adjustment->adjustment_type === 'late'
            && $adjustment->final_late_minutes !== null
        ) {
            return (int) $adjustment->final_late_minutes;
        }

        return (int) ($log->chargeable_late_minutes ?? $log->late_minutes ?? 0);
    }

    private function undertimeMinutes(TimeLog $log): int
    {
        $adjustment = $log->latestLateAdjustment;

        if (
            $adjustment
            && $adjustment->status === 'approved'
            && $adjustment->adjustment_type === 'undertime'
            && $adjustment->final_undertime_minutes !== null
        ) {
            return (int) $adjustment->final_undertime_minutes;
        }

        return (int) ($log->undertime_minutes ?? 0);
    }

    private function unpaidLeaveDays(Collection $employeeIds, string $start, string $end): Collection
    {
        $table = (new LeaveRequestDate())->getTable();

        $column = collect(['leave_date', 'date', 'work_date'])
            ->first(fn ($c) => Schema::hasColumn($table, $c));

        if (!$column) {
            return collect();
        }

        return LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->where('approved_pay_type', 'unpaid')
            ->withCount([
                'leaveDates as days_in_cutoff' => fn ($q) => $q->whereBetween($column, [$start, $end]),
            ])
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($requests) => $requests->sum('days_in_cutoff'));
    }

    private function contributions(
        PayrollPeriod $period,
        Employee $employee,
        $salary,
        float $monthlyRate,
        Collection $types,
        Collection $settings,
        Collection $schedules
    ): array {
        $lines = [];

        foreach ($types as $type) {
            $setting = $settings->get($type->id, collect())
                ->filter(fn ($s) => (!$s->department_id || (int) $s->department_id === (int) $employee->department_id)
                    && (!$s->salary_type_id || (int) $s->salary_type_id === (int) $salary->salary_type_id))
                ->sortByDesc(fn ($s) => ($s->department_id ? 2 : 0) + ($s->salary_type_id ? 1 : 0))
                ->first();

            if (!$setting) {
                continue;
            }

            $percent = (int) $period->cutoff_number === 1
                ? (float) $setting->first_cutoff_percent
                : (float) $setting->second_cutoff_percent;

            if ($percent <= 0) {
                continue;
            }

            $schedule = $schedules->get($type->id)?->first();

            if (!$schedule) {
                continue;
            }

            [$basis, $employeeShare, $employerShare, $ec] = $this->monthlyShare($schedule, $monthlyRate);

            $factor = $percent / 100;

            $lines[] = [
                'contribution_type_id' => $type->id,
                'contribution_rate_schedule_id' => $schedule->id,
                'code' => $type->contribution_code,
                'name' => $type->contribution_name,
                'salary_basis_amount' => round($basis, 2),
                'employee_share' => round($employeeShare * $factor, 2),
                'employer_share' => round($employerShare * $factor, 2),
                'employer_ec_amount' => round($ec * $factor, 2),
            ];
        }

        return $lines;
    }

    private function monthlyShare(ContributionRateSchedule $schedule, float $monthlyRate): array
    {
        $base = $monthlyRate;

        if ($schedule->minimum_salary_base !== null && $base < (float) $schedule->minimum_salary_base) {
            $base = (float) $schedule->minimum_salary_base;
        }

        if ($schedule->maximum_salary_base !== null && (float) $schedule->maximum_salary_base > 0
            && $base > (float) $schedule->maximum_salary_base) {
            $base = (float) $schedule->maximum_salary_base;
        }

        $rate = fn ($value) => (float) $value > 1 ? (float) $value / 100 : (float) $value;

        return match ($schedule->calculation_method) {
            'fixed' => [
                $base,
                (float) $schedule->employee_amount,
                (float) $schedule->employer_amount,
                0.0,
            ],
            'percentage' => [
                $base,
                $base * $rate($schedule->employee_rate),
                $base * $rate($schedule->employer_rate),
                0.0,
            ],
            'bracket' => $this->bracketShare($schedule, $base, $rate),
            default => [$base, 0.0, 0.0, 0.0],
        };
    }

    private function bracketShare(ContributionRateSchedule $schedule, float $base, callable $rate): array
    {
        $brackets = $schedule->brackets->sortBy('salary_from')->values();

        $bracket = $brackets->first(fn ($b) => $base >= (float) $b->salary_from
            && ($b->salary_to === null || $base <= (float) $b->salary_to))
            ?? $brackets->last(fn ($b) => $base >= (float) $b->salary_from)
            ?? $brackets->first();

        if (!$bracket) {
            return [$base, 0.0, 0.0, 0.0];
        }

        $credit = $bracket->monthly_salary_credit !== null
            ? (float) $bracket->monthly_salary_credit
            : $base;

        $employee = $bracket->employee_amount !== null
            ? (float) $bracket->employee_amount
            : $credit * $rate($bracket->employee_rate);

        $employer = $bracket->employer_amount !== null
            ? (float) $bracket->employer_amount
            : $credit * $rate($bracket->employer_rate);

        return [$credit, $employee, $employer, (float) ($bracket->employer_ec_amount ?? 0)];
    }
}
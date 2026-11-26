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
use App\Models\PayrollHolidayPay;
use App\Models\PayrollPeriod;
use App\Models\SalaryAdjustment;
use App\Models\SalarySetup;
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

    public function __construct(
        private readonly HolidayPayService $holidayPay,
        private readonly WithholdingTaxService $withholdingTax
    ) {
    }

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

        // Salary adjustments (approved or applied) used to prorate a rate change inside the cut-off
        $salaryAdjustments = SalaryAdjustment::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['approved', 'applied'])
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        // Additional allowances (employee_allowance), copied there when an adjustment is applied
        $extraAllowances = DB::table('employee_allowance')
            ->whereIn('salary_setup_id', $employees->pluck('salarySetup.id')->filter()->values())
            ->orderBy('id')
            ->get(['salary_setup_id', 'allowance_name', 'allowance'])
            ->groupBy('salary_setup_id');

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
            ->orderByDesc('effective_from')
            ->get()
            ->unique('contribution_type_id')
            ->keyBy('contribution_type_id');

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

        // Holidays, coverage, schedules and rules for this cut-off (loaded once)
        $holidayContext = $this->holidayPay->prepare(
            (int) $period->company_id,
            $start,
            $end,
            $employeeIds
        );

        $generated = [];
        $skipped = [];
        $warnings = [];

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
            $holidayContext,
            $salaryAdjustments,
            $extraAllowances,
            $start,
            $end,
            &$generated,
            &$skipped,
            &$warnings
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

                if (
                    blank($salary->bank_name)
                    || blank($salary->account_name)
                    || blank($salary->account_number)
                ) {
                    // Remove a leftover draft from an earlier generation, so it isn't finalized by mistake
                    // (its holiday pay rows are removed with it through the foreign key cascade)
                    if ($current && $current->status === 'draft') {
                        $current->contributions()->delete();
                        $current->delete();
                    }

                    $skip('Bank details are incomplete.');
                    continue;
                }

                $assignment = $assignments->get($employee->id);
                $frequency = $assignment->payrollGroup?->frequency ?? 'semi_monthly';
                $perMonth = self::PERIODS_PER_MONTH[$frequency] ?? 2;

                $rateType = $this->rateType($salary->salaryType?->type_name);

                // Salary in effect for each part of the cut-off (one segment when nothing changed)
                $segments = $this->salarySegments(
                    $salary,
                    $salaryAdjustments->get($employee->id, collect()),
                    $start,
                    $end
                );

                $endSegment = end($segments);
                $basic = $endSegment['basic'];

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

                // Days with only one punch. Nothing is deducted for these, so HR must review them
                // (apply a time log correction, or file unpaid leave if the employee did not work).
                $incompleteLogs = $logs
                    ->filter(fn ($log) => (bool) $log->time_in !== (bool) $log->time_out)
                    ->sortBy('work_date')
                    ->map(fn ($log) => [
                        'time_log_id' => $log->id,
                        'work_date' => $log->work_date?->format('Y-m-d'),
                        'time_in' => $log->time_in?->format('Y-m-d H:i:s'),
                        'time_out' => $log->time_out?->format('Y-m-d H:i:s'),
                        'missing' => $log->time_out ? 'time_in' : 'time_out',
                        'payroll_effect' => $rateType === 'monthly'
                            ? 'Paid as a full day (basic salary not reduced).'
                            : 'Not paid for this day.',
                    ])
                    ->values()
                    ->all();
                $workedMinutes = (int) $logs->sum('worked_minutes');

                $basicPay = match ($rateType) {
                    'daily' => $daysWorked * $dailyRate,
                    'hourly' => $workedMinutes / 60 * $basic,
                    default => $monthlyRate / $perMonth,
                };

                $allowancePay = $endSegment['allowance'] / $perMonth;

                // Salary adjustment effective inside this cut-off: old rate before, new rate from its date
                if (count($segments) > 1) {
                    [$basicPay, $allowancePay] = $this->proratedPay(
                        $segments,
                        $rateType,
                        $perMonth,
                        $logs,
                        $start,
                        $end
                    );
                }

                // Days and pay per salary segment, for the payroll breakdown
                // (e.g. "Nov 16–24 · 2 day(s) × ₱650", "Nov 25–30 · 1 day(s) × ₱750").
                $segments = $this->describeSegments($segments, $rateType, $perMonth, $logs, $start, $end);

                // Additional allowances, split per cut-off like the main allowance
                $extraLines = $extraAllowances->get($salary->id, collect())
                    ->map(fn ($row) => [
                        'allowance_name' => $row->allowance_name,
                        'monthly_amount' => round((float) $row->allowance, 2),
                        'amount' => round((float) $row->allowance / $perMonth, 2),
                    ])
                    ->values()
                    ->all();

                // Main allowance alone, before the additional allowances (withholding tax).
                $mainAllowancePay = $allowancePay;
                $allowancePay += collect($extraLines)->sum('amount');

                $lateMinutes = (int) $logs->sum(fn ($log) => $this->lateMinutes($log));
                $undertimeMinutes = (int) $logs->sum(fn ($log) => $this->undertimeMinutes($log));
                $leaveDays = (float) ($unpaidLeaveDays[$employee->id] ?? 0);

                $deductsTime = $rateType !== 'hourly';

                $lateDeduction = $deductsTime ? $lateMinutes * $minuteRate : 0;
                $undertimeDeduction = $deductsTime ? $undertimeMinutes * $minuteRate : 0;
                $leaveDeduction = $rateType === 'monthly' ? $leaveDays * $dailyRate : 0;

                $employeeOvertime = $overtime->get($employee->id, collect());

                $overtimeMinutes = (int) $employeeOvertime
                    ->sum(fn ($ot) => $ot->start_at && $ot->end_at
                        ? (int) abs($ot->start_at->diffInMinutes($ot->end_at))
                        : 0);

                $overtimePay = $overtimeMinutes * $minuteRate * self::OVERTIME_MULTIPLIER;

                // Holiday pay: unworked covered holidays (daily-paid) and premiums on worked holidays
                $holidayLines = $this->holidayPay->linesFor(
                    $holidayContext,
                    $employee->id,
                    $rateType,
                    $dailyRate,
                    $minuteRate,
                    $logs,
                    $employeeOvertime
                );

                $holidayPay = round(collect($holidayLines)->sum('amount'), 2);

                // How each holiday applies to this employee (same result as Payroll Readiness)
                $holidayDays = $this->holidayPay->daysFor($holidayContext, $employee->id, $logs);

                // Monthly salary includes every working day, so a holiday the employee is NOT paid for
                // (not covered, or absent the working day before) is taken off. Daily-paid employees
                // are simply not paid for days they did not work.
                $unpaidHolidayDays = $rateType === 'monthly'
                    ? collect($holidayDays)
                        ->filter(fn ($day) => !$day['worked'] && !$day['paid'] && !$day['rest_day'])
                        ->count()
                    : 0;

                $unpaidHolidayDeduction = $unpaidHolidayDays * $dailyRate;

                $gross = max(0, $basicPay + $allowancePay + $overtimePay + $holidayPay
                    - $lateDeduction - $undertimeDeduction - $leaveDeduction - $unpaidHolidayDeduction);

                $contributions = $this->contributions(
                    $period,
                    $monthlyRate,
                    $contributionTypes,
                    $settings,
                    $schedules
                );

                $contributionsTotal = collect($contributions)->sum('employee_share');

                // Withholding tax (BIR): after contributions, before loans.
                $taxResult = $this->withholdingTax->compute([
                    'employee_id' => $employee->id,
                    'frequency' => $frequency,
                    'date' => $end,
                    'daily_rate' => (float) $dailyRate,
                    'basic' => (float) $basicPay,
                    'overtime' => (float) $overtimePay,
                    'holiday' => (float) $holidayPay,
                    'main_allowance' => (float) $mainAllowancePay,
                    'extra_allowances' => $extraLines,
                    'attendance_deductions' => (float) ($lateDeduction + $undertimeDeduction + $leaveDeduction + $unpaidHolidayDeduction),
                    'contributions' => (float) $contributionsTotal,
                ]);
                $tax = min($taxResult['tax'], max(0, $gross - $contributionsTotal));

                $available = max(0, $gross - $contributionsTotal - $tax);
                $loanLines = [];

                foreach ($loans->get($employee->id, collect()) as $loan) {
                    // deduction_amount is per month. Each cutoff takes its percent of it (like contributions);
                    // a monthly payroll group has one payroll a month, so it takes the whole amount.
                    $percent = $frequency === 'monthly'
                        ? 100.0
                        : $loan->percentForCutoff((int) $period->cutoff_number);

                    if ($percent <= 0) {
                        continue; // not deducted in this cutoff
                    }

                    $scheduled = round((float) $loan->deduction_amount * $percent / 100, 2);
                    $remaining = max(0, (float) $loan->opening_balance + (float) $loan->transactions_total);
                    $amount = min($scheduled, $remaining, $available);

                    if ($amount <= 0) {
                        continue;
                    }

                    $available -= $amount;

                    $loanLines[] = [
                        'employee_loan_id' => $loan->id,
                        'loan_type' => $loan->loanType?->loan_name ?? 'Loan',
                        'reference' => $loan->loan_reference_no,
                        'cutoff_number' => (int) $period->cutoff_number,
                        'cutoff_percent' => $percent,
                        'scheduled_amount' => $scheduled,
                        'amount' => round($amount, 2),
                        'balance_before' => round($remaining, 2),
                        'balance_after' => round($remaining - $amount, 2),
                        'partial' => $amount < $scheduled,
                    ];
                }

                $loanTotal = collect($loanLines)->sum('amount');
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
                    'allowance' => $endSegment['allowance'],
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
                    'holiday_pay' => $holidayPay,
                    'unpaid_holiday_days' => $unpaidHolidayDays,
                    'unpaid_holiday_deduction' => round($unpaidHolidayDeduction, 2),
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
                        'holidays' => $holidayLines,
                        'holiday_days' => $holidayDays,
                        'salary_segments' => $segments,
                        'additional_allowances' => $extraLines,
                        'tax' => $taxResult['detail'],
                        'incomplete_logs' => $incompleteLogs,
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

                // Rebuild holiday pay lines on every (re)generation
                $payroll->holidayPays()->delete();

                foreach ($holidayLines as $line) {
                    PayrollHolidayPay::create([
                        'payroll_id' => $payroll->id,
                        'employee_id' => $employee->id,
                        'holiday_id' => $line['holiday_id'],
                        'holiday_pay_rule_id' => $line['holiday_pay_rule_id'],
                        'time_log_id' => $line['time_log_id'],
                        'holiday_date' => $line['holiday_date'],
                        'scenario' => $line['scenario'],
                        'worked_minutes' => $line['worked_minutes'],
                        'overtime_minutes' => $line['overtime_minutes'],
                        'daily_rate' => $line['daily_rate'],
                        'rate_percent' => $line['rate_percent'],
                        'amount' => $line['amount'],
                    ]);
                }

                $generated[] = [
                    'payroll_id' => $payroll->id,
                    'employee_id' => $employee->id,
                    'name' => $label,
                    'gross_pay' => round($gross, 2),
                    'total_deductions' => round($totalDeductions, 2),
                    'net_pay' => round($net, 2),
                    'incomplete_logs' => $incompleteLogs,
                ];

                if ($incompleteLogs) {
                    $count = count($incompleteLogs);

                    $warnings[] = [
                        'payroll_id' => $payroll->id,
                        'employee_id' => $employee->id,
                        'name' => $label,
                        'code' => 'incomplete_time_logs',
                        'rate_type' => $rateType,
                        'message' => "{$count} day(s) with time in but no time out (or the reverse). "
                            . ($rateType === 'monthly'
                                ? 'Basic salary was still paid in full for these days.'
                                : 'These days were not paid.')
                            . ' Apply a time log correction, or file unpaid leave if the employee did not work, then regenerate.',
                        'logs' => $incompleteLogs,
                    ];
                }
            }
        });

        return [
            'generated' => $generated,
            'skipped' => $skipped,
            'warnings' => $warnings,
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
        return HolidayPayService::rateType($typeName);
    }

    /**
     * Splits the cut-off into the salary in effect for each part.
     *
     * - Applied adjustments already changed salary_setups, so for one effective
     *   after the cut-off start, the cut-off starts on its previous values.
     * - An approved (not yet applied) adjustment effective on/before the start
     *   is used for the whole cut-off.
     * - Every approved/applied adjustment effective inside the cut-off starts a
     *   new segment on its effective date with its new values.
     *
     * @return array<int, array{from: string, to: string, days: int, basic: float, allowance: float, salary_adjustment_id: ?int}>
     */
    private function salarySegments(SalarySetup $salary, Collection $adjustments, string $start, string $end): array
    {
        $adjustments = $adjustments
            ->filter(fn (SalaryAdjustment $a) => (int) $a->salary_setup_id === (int) $salary->id && $a->effective_date)
            ->sortBy(fn (SalaryAdjustment $a) => $a->effective_date->format('Y-m-d') . sprintf('-%010d', $a->id))
            ->values();

        $basic = (float) $salary->basic_salary;
        $allowance = (float) $salary->allowance;
        $adjustmentId = null;

        $firstLaterApplied = $adjustments->first(
            fn (SalaryAdjustment $a) => $a->status === 'applied' && $a->effective_date->format('Y-m-d') > $start
        );

        if ($firstLaterApplied) {
            $basic = (float) $firstLaterApplied->previous_basic_salary;
            $allowance = (float) ($firstLaterApplied->previous_allowance ?? $allowance);
        } else {
            $approvedBefore = $adjustments->last(
                fn (SalaryAdjustment $a) => $a->status === 'approved' && $a->effective_date->format('Y-m-d') <= $start
            );

            if ($approvedBefore) {
                $basic = (float) $approvedBefore->new_basic_salary;
                $allowance = (float) ($approvedBefore->new_allowance ?? $allowance);
                $adjustmentId = $approvedBefore->id;
            }
        }

        $segments = [];
        $from = $start;

        $inside = $adjustments->filter(function (SalaryAdjustment $a) use ($start, $end) {
            $date = $a->effective_date->format('Y-m-d');

            return $date > $start && $date <= $end;
        });

        foreach ($inside as $adjustment) {
            $date = $adjustment->effective_date->format('Y-m-d');

            if ($date > $from) {
                $segments[] = $this->segment($from, $this->dayBefore($date), $basic, $allowance, $adjustmentId);
                $from = $date;
            }

            $basic = (float) $adjustment->new_basic_salary;
            $allowance = (float) ($adjustment->new_allowance ?? $allowance);
            $adjustmentId = $adjustment->id;
        }

        $segments[] = $this->segment($from, $end, $basic, $allowance, $adjustmentId);

        return $segments;
    }

    private function segment(string $from, string $to, float $basic, float $allowance, ?int $adjustmentId): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'days' => $this->daysBetween($from, $to),
            'basic' => $basic,
            'allowance' => $allowance,
            'salary_adjustment_id' => $adjustmentId,
        ];
    }

    /**
     * Basic and allowance pay when the salary changes inside the cut-off.
     * Monthly pay and allowances are split by calendar days in each segment;
     * daily/hourly pay uses the rate in effect on each time log's work date.
     *
     * @return array{0: float, 1: float}
     */
    private function proratedPay(array $segments, string $rateType, float $perMonth, Collection $logs, string $start, string $end): array
    {
        $cutoffDays = $this->daysBetween($start, $end);
        $basicPay = 0.0;
        $allowancePay = 0.0;

        foreach ($segments as $segment) {
            $share = $segment['days'] / $cutoffDays;

            $allowancePay += $segment['allowance'] / $perMonth * $share;

            if ($rateType === 'monthly') {
                $basicPay += $segment['basic'] / $perMonth * $share;
            }
        }

        if ($rateType !== 'monthly') {
            foreach ($logs as $log) {
                $date = $log->work_date?->format('Y-m-d');

                $segment = collect($segments)->first(
                    fn ($s) => $date !== null && $date >= $s['from'] && $date <= $s['to']
                ) ?? end($segments);

                if ($rateType === 'daily' && $log->time_in && $log->time_out) {
                    $basicPay += $segment['basic'];
                }

                if ($rateType === 'hourly') {
                    $basicPay += (int) $log->worked_minutes / 60 * $segment['basic'];
                }
            }
        }

        return [$basicPay, $allowancePay];
    }

    /**
     * Adds to each salary segment what it contributed to this payroll, for
     * the breakdown: days_worked, worked_minutes, basic_pay, and the wage
     * order or adjustment that set its salary (source). Pay is computed the
     * same way as proratedPay(), so the lines add up to basic_pay.
     */
    private function describeSegments(array $segments, string $rateType, float $perMonth, Collection $logs, string $start, string $end): array
    {
        $cutoffDays = max(1, $this->daysBetween($start, $end));

        $sources = \Illuminate\Support\Facades\DB::table('salary_adjustments')
            ->leftJoin('wage_orders', 'wage_orders.id', '=', 'salary_adjustments.wage_order_id')
            ->whereIn('salary_adjustments.id', collect($segments)->pluck('salary_adjustment_id')->filter()->all())
            ->get(['salary_adjustments.id', 'salary_adjustments.adjustment_type', 'wage_orders.order_no'])
            ->keyBy('id');

        return collect($segments)->map(function (array $segment) use ($rateType, $perMonth, $logs, $cutoffDays, $sources) {
            $inside = $logs->filter(function ($log) use ($segment) {
                $date = $log->work_date?->format('Y-m-d');

                return $date !== null && $date >= $segment['from'] && $date <= $segment['to'];
            });

            $daysWorked = $inside->filter(fn ($log) => $log->time_in && $log->time_out)->count();
            $minutes = (int) $inside->sum('worked_minutes');

            $pay = match ($rateType) {
                'daily' => $daysWorked * $segment['basic'],
                'hourly' => $minutes / 60 * $segment['basic'],
                default => $segment['basic'] / $perMonth * ($segment['days'] / $cutoffDays),
            };

            $source = $segment['salary_adjustment_id'] ? $sources->get($segment['salary_adjustment_id']) : null;

            return $segment + [
                'days_worked' => $daysWorked,
                'worked_minutes' => $minutes,
                'basic_pay' => round($pay, 2),
                'source' => $source
                    ? ($source->order_no ? "Wage order {$source->order_no}" : 'Salary adjustment #'.$segment['salary_adjustment_id'])
                    : null,
            ];
        })->values()->all();
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int) \Illuminate\Support\Carbon::parse($from)->diffInDays(\Illuminate\Support\Carbon::parse($to)) + 1;
    }

    private function dayBefore(string $date): string
    {
        return \Illuminate\Support\Carbon::parse($date)->subDay()->format('Y-m-d');
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
        float $monthlyRate,
        Collection $types,
        Collection $settings,
        Collection $schedules
    ): array {
        $lines = [];
        $cutoffNumber = (int) $period->cutoff_number;

        foreach ($types as $type) {
            /** @var ContributionDeductionSetting|null $setting */
            $setting = $settings->get($type->id);

            // No setting = this contribution is not deducted by payroll.
            if (!$setting) {
                continue;
            }

            // 1st cutoff period uses first_cutoff_percent, 2nd uses second_cutoff_percent.
            $percent = $setting->percentForCutoff($cutoffNumber);

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
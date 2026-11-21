<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeeLoanTransaction;
use App\Models\PayrollPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The logged-in employee's own loans (read-only).
 * Loans are created by HR in Admin › Employee Loans; payments come from payroll deductions
 * (recorded when payroll is finalized) and manual payments.
 */
class LoanController extends Controller
{
    /** Transactions that reduce the balance. */
    private const PAYMENT_TYPES = ['payroll_deduction', 'manual_payment'];

    // GET /api/employee/loans
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return $this->noEmployee();
        }

        $loans = EmployeeLoan::query()
            ->where('employee_id', $employee->id)
            ->with([
                'loanType',
                'transactions' => fn ($q) => $q->where('status', true),
            ])
            ->orderByDesc('loan_date')
            ->orderByDesc('id')
            ->get();

        $context = $this->context($employee);

        $rows = $loans
            ->map(fn (EmployeeLoan $loan) => $this->formatLoan($loan, $loan->transactions, $context))
            ->values();

        $active = $rows->where('summary.status', 'Active');

        return response()->json([
            'success' => true,
            'message' => 'Loans retrieved successfully.',
            'data' => [
                'loans' => $rows,
                'totals' => [
                    'count' => $rows->count(),
                    'active_count' => $active->count(),
                    'outstanding' => round($rows->sum('summary.outstanding'), 2),
                    'paid' => round($rows->sum('summary.paid'), 2),
                    // What is deducted per payroll for all active loans together
                    'installment_per_payroll' => round($active->sum('installment'), 2),
                ],
            ],
        ]);
    }

    // GET /api/employee/loans/{employeeLoan}
    public function show(Request $request, EmployeeLoan $employeeLoan): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return $this->noEmployee();
        }

        // Another employee's loan: treat as not found
        if ((int) $employeeLoan->employee_id !== (int) $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Loan not found.',
            ], 404);
        }

        $employeeLoan->load('loanType');

        $transactions = $employeeLoan->transactions()
            ->where('status', true)
            ->with('payroll.payrollPeriod:id,period_name')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        // Payment history with the balance after each entry
        $balance = (float) $employeeLoan->opening_balance;

        $history = $transactions->map(function (EmployeeLoanTransaction $t) use (&$balance) {
            $balance += (float) $t->amount_delta;
            $isPayment = in_array($t->transaction_type, self::PAYMENT_TYPES, true);

            return [
                'id' => $t->id,
                'date' => $t->transaction_date?->format('Y-m-d'),
                'type' => $t->transaction_type,
                'method' => match ($t->transaction_type) {
                    'payroll_deduction' => 'Payroll deduction',
                    'manual_payment' => 'Manual payment',
                    default => 'Adjustment',
                },
                // Positive for payments; adjustments keep their sign (+ adds to the balance)
                'amount' => $isPayment ? abs((float) $t->amount_delta) : (float) $t->amount_delta,
                'reference' => $t->reference_no,
                'payroll_period' => $t->payroll?->payrollPeriod?->period_name,
                'remarks' => $t->remarks,
                'balance_after' => round(max(0, $balance), 2),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Loan retrieved successfully.',
            'data' => $this->formatLoan($employeeLoan, $transactions, $this->context($employee)) + [
                'payments' => $history,
            ],
        ]);
    }

    /**
     * @param  Collection<int, EmployeeLoanTransaction>  $transactions  active transactions only
     */
    private function formatLoan(EmployeeLoan $loan, Collection $transactions, array $context): array
    {
        $principal = (float) $loan->principal_amount;
        $totalPayable = (float) $loan->total_payable_amount;
        $installment = (float) $loan->deduction_amount;

        $paid = $transactions
            ->whereIn('transaction_type', self::PAYMENT_TYPES)
            ->sum(fn ($t) => abs((float) $t->amount_delta));

        // Same balance payroll uses: opening balance + all active transactions
        $outstanding = max(0, (float) $loan->opening_balance + $transactions->sum(fn ($t) => (float) $t->amount_delta));
        $totalDue = $outstanding + $paid;

        $status = match (true) {
            $outstanding <= 0 => 'Fully Paid',
            ! $loan->status => 'Inactive',
            default => 'Active',
        };

        return [
            'id' => $loan->id,
            'reference_no' => $loan->loan_reference_no,
            'loan_type' => $loan->loanType ? [
                'id' => $loan->loanType->id,
                'code' => $loan->loanType->loan_code,
                'name' => $loan->loanType->loan_name,
                'provider' => $loan->loanType->provider,
            ] : null,

            'principal' => round($principal, 2),
            'interest' => round(max(0, $totalPayable - $principal), 2),
            'total_payable' => round($totalPayable, 2),
            'installment' => round($installment, 2), // per month
            'deduction_schedule' => $loan->deductionScheduleLabel(),
            'first_cutoff_amount' => round($installment * (float) $loan->first_cutoff_percent / 100, 2),
            'second_cutoff_amount' => round($installment * (float) $loan->second_cutoff_percent / 100, 2),
            'frequency' => $context['frequency'],
            'method' => 'Payroll deduction',

            'loan_date' => $loan->loan_date?->format('Y-m-d'),
            'deduction_start_date' => $loan->deduction_start_date?->format('Y-m-d'),
            'next_due_date' => $status === 'Active' ? $this->nextDueDate($loan, $context) : null,
            'remaining_installments' => $status === 'Active' && $installment > 0
                ? (int) ceil(round($outstanding / $installment, 4))
                : 0,
            'remarks' => $loan->remarks,

            'summary' => [
                'total_due' => round($totalDue, 2),
                'paid' => round($paid, 2),
                'outstanding' => round($outstanding, 2),
                'status' => $status,
                'progress' => $totalDue > 0 ? (int) min(100, floor($paid / $totalDue * 100)) : 100,
            ],
        ];
    }

    /** Payroll frequency and upcoming payroll periods, loaded once per request. */
    private function context(Employee $employee): array
    {
        $today = today()->toDateString();

        $frequency = $employee->payrollGroupAssignments()
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->with('payrollGroup:id,frequency')
            ->latest('effective_from')
            ->first()
            ?->payrollGroup?->frequency;

        $companyId = $employee->department?->company_id;

        $upcoming = PayrollPeriod::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereDate('cutoff_end', '>=', $today)
            ->orderBy('cutoff_end')
            ->limit(24)
            ->get(['id', 'cutoff_number', 'cutoff_end', 'payment_date']);

        return [
            'frequency' => $frequency,
            'upcoming_periods' => $upcoming,
        ];
    }

    /**
     * Payment date of the first upcoming payroll on/after the deduction start date
     * whose cutoff (1st or 2nd) actually deducts this loan.
     */
    private function nextDueDate(EmployeeLoan $loan, array $context): ?string
    {
        $start = $loan->deduction_start_date?->format('Y-m-d') ?? today()->toDateString();

        $period = $context['upcoming_periods']->first(
            fn (PayrollPeriod $p) => $p->cutoff_end?->format('Y-m-d') >= $start
                && ($context['frequency'] === 'monthly' || $loan->percentForCutoff((int) $p->cutoff_number) > 0)
        );

        return ($period?->payment_date ?? $period?->cutoff_end)?->format('Y-m-d');
    }

    private function noEmployee(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'No employee profile associated with this account.',
        ], 404);
    }
}

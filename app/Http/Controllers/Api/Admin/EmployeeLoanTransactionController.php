<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeLoan;
use App\Models\EmployeeLoanTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeLoanTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'employee_loan_id' => ['nullable', 'integer', 'exists:employee_loans,id'],
            'loan_type_id' => ['nullable', 'integer', 'exists:loan_types,id'],
            'transaction_type' => ['nullable', 'in:payroll_deduction,manual_payment,adjustment,reversal'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'status' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $transactions = EmployeeLoanTransaction::query()
            ->with($this->relations())
            ->when($filters['employee_id'] ?? null, fn ($query, $employeeId) => $query->whereHas(
                'employeeLoan',
                fn ($loanQuery) => $loanQuery->where('employee_id', $employeeId)
            ))
            ->when($filters['employee_loan_id'] ?? null, fn ($query, $loanId) => $query->where('employee_loan_id', $loanId))
            ->when($filters['loan_type_id'] ?? null, fn ($query, $loanTypeId) => $query->whereHas(
                'employeeLoan',
                fn ($loanQuery) => $loanQuery->where('loan_type_id', $loanTypeId)
            ))
            ->when($filters['transaction_type'] ?? null, fn ($query, $type) => $query->where('transaction_type', $type))
            ->when($filters['from_date'] ?? null, fn ($query, $date) => $query->whereDate('transaction_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($query, $date) => $query->whereDate('transaction_date', '<=', $date))
            ->when(array_key_exists('status', $filters), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)
            ->through(fn (EmployeeLoanTransaction $transaction) => $this->formatTransaction($transaction));

        return response()->json([
            'success' => true,
            'message' => 'Employee loan deduction history retrieved successfully.',
            'data' => $transactions,
        ]);
    }

    public function history(EmployeeLoan $employeeLoan): JsonResponse
    {
        $employeeLoan->load(['employee', 'loanType', 'createdBy']);

        $transactions = $employeeLoan->transactions()
            ->with($this->relations())
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $runningBalance = (float) $employeeLoan->opening_balance;
        $history = $transactions->map(function (EmployeeLoanTransaction $transaction) use (&$runningBalance): array {
            if ($transaction->status) {
                $runningBalance += (float) $transaction->amount_delta;
            }

            return [
                ...$this->formatTransaction($transaction),
                'balance_after' => round($runningBalance, 2),
            ];
        })->values();

        $activeTransactions = $transactions->where('status', true);
        $deducted = $activeTransactions
            ->whereIn('transaction_type', ['payroll_deduction', 'manual_payment'])
            ->sum(fn (EmployeeLoanTransaction $transaction): float => abs((float) $transaction->amount_delta));

        return response()->json([
            'success' => true,
            'message' => 'Employee loan transaction history retrieved successfully.',
            'data' => [
                'loan' => $this->formatLoan($employeeLoan),
                'summary' => [
                    'opening_balance' => (float) $employeeLoan->opening_balance,
                    'total_deducted' => round($deducted, 2),
                    'net_amount_delta' => round($activeTransactions->sum('amount_delta'), 2),
                    'current_balance' => round($runningBalance, 2),
                    'transaction_count' => $transactions->count(),
                ],
                'transactions' => $history,
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'employeeLoan.employee',
            'employeeLoan.loanType',
            'payroll',
            'createdBy:id,name,email',
        ];
    }

    private function formatTransaction(EmployeeLoanTransaction $transaction): array
    {
        $loan = $transaction->employeeLoan;
        $employee = $loan?->employee;
        $loanType = $loan?->loanType;

        return [
            'id' => $transaction->id,
            'employee_loan_id' => $transaction->employee_loan_id,
            'employee_id' => $loan?->employee_id,
            'loan_type_id' => $loan?->loan_type_id,
            'employee' => $employee ? [
                'id' => $employee->id,
                'emp_id' => $employee->emp_id,
                'name' => trim(implode(' ', array_filter([
                    $employee->firstname,
                    $employee->middlename,
                    $employee->lastname,
                ]))),
            ] : null,
            'loan' => $loan ? [
                'id' => $loan->id,
                'loan_reference_no' => $loan->loan_reference_no,
                'principal_amount' => (float) $loan->principal_amount,
                'total_payable_amount' => (float) $loan->total_payable_amount,
                'opening_balance' => (float) $loan->opening_balance,
                'deduction_amount' => (float) $loan->deduction_amount,
                'status' => (bool) $loan->status,
            ] : null,
            'loan_type' => $loanType ? [
                'id' => $loanType->id,
                'loan_code' => $loanType->loan_code,
                'loan_name' => $loanType->loan_name,
                'provider' => $loanType->provider,
            ] : null,
            'payroll_id' => $transaction->payroll_id,
            'transaction_type' => $transaction->transaction_type,
            'amount_delta' => (float) $transaction->amount_delta,
            'deduction_amount' => in_array($transaction->transaction_type, ['payroll_deduction', 'manual_payment'], true)
                ? abs((float) $transaction->amount_delta)
                : 0.0,
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
            'reference_no' => $transaction->reference_no,
            'remarks' => $transaction->remarks,
            'status' => (bool) $transaction->status,
            'created_by' => $transaction->createdBy ? [
                'id' => $transaction->createdBy->id,
                'name' => $transaction->createdBy->name,
                'email' => $transaction->createdBy->email,
            ] : null,
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }

    private function formatLoan(EmployeeLoan $loan): array
    {
        $employee = $loan->employee;
        $loanType = $loan->loanType;

        return [
            'id' => $loan->id,
            'employee_id' => $loan->employee_id,
            'loan_type_id' => $loan->loan_type_id,
            'loan_reference_no' => $loan->loan_reference_no,
            'employee' => $employee ? [
                'id' => $employee->id,
                'emp_id' => $employee->emp_id,
                'name' => trim(implode(' ', array_filter([
                    $employee->firstname,
                    $employee->middlename,
                    $employee->lastname,
                ]))),
            ] : null,
            'loan_type' => $loanType ? [
                'id' => $loanType->id,
                'loan_code' => $loanType->loan_code,
                'loan_name' => $loanType->loan_name,
                'provider' => $loanType->provider,
            ] : null,
            'principal_amount' => (float) $loan->principal_amount,
            'total_payable_amount' => (float) $loan->total_payable_amount,
            'opening_balance' => (float) $loan->opening_balance,
            'deduction_amount' => (float) $loan->deduction_amount,
            'loan_date' => $loan->loan_date?->format('Y-m-d'),
            'deduction_start_date' => $loan->deduction_start_date?->format('Y-m-d'),
            'remarks' => $loan->remarks,
            'status' => (bool) $loan->status,
        ];
    }
}

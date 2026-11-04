<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeLoan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeLoanController extends Controller
{
    public function index(): JsonResponse
    {
        $loans = EmployeeLoan::query()
            ->with(['employee', 'loanType', 'createdBy'])
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Employee loans retrieved successfully.',
            'data' => $loans
                ->map(fn (EmployeeLoan $loan) => $this->formatLoan($loan))
                ->values(),
        ]);
    }

    public function show(EmployeeLoan $employeeLoan): JsonResponse
    {
        $employeeLoan->load([
            'employee',
            'loanType',
            'createdBy',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee loan retrieved successfully.',
            'data' => $this->formatLoan($employeeLoan),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(
            $this->rules($request)
        );

        $validated['loan_reference_no'] = strtoupper(
            trim($validated['loan_reference_no'])
        );

        // Opening balance initially equals total payable.
        // Actual deductions will be recorded separately.
        $validated['opening_balance'] =
            $validated['total_payable_amount'];

        // Never trust created_by from the frontend.
        $validated['created_by'] = $request->user()->id;

        $loan = EmployeeLoan::create($validated);

        $loan->load(['employee', 'loanType', 'createdBy']);

        return response()->json([
            'success' => true,
            'message' => 'Employee loan created successfully.',
            'data' => $this->formatLoan($loan),
        ], 201);
    }

    public function update(
        Request $request,
        EmployeeLoan $employeeLoan
    ): JsonResponse {
        // Prevent changing financial terms once transactions exist.
        if ($employeeLoan->transactions()->exists()) {
            return response()->json([
                'success' => false,
                'message' =>
                    'This loan already has transactions. ' .
                    'Its financial terms cannot be edited through this form.',
            ], 422);
        }

        $validated = $request->validate(
            $this->rules($request, $employeeLoan)
        );

        $validated['loan_reference_no'] = strtoupper(
            trim($validated['loan_reference_no'])
        );

        $validated['opening_balance'] =
            $validated['total_payable_amount'];

        $employeeLoan->update($validated);

        $employeeLoan->refresh()->load([
            'employee',
            'loanType',
            'createdBy',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee loan updated successfully.',
            'data' => $this->formatLoan($employeeLoan),
        ]);
    }

    private function rules(
        Request $request,
        ?EmployeeLoan $loan = null
    ): array {
        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id'),
            ],

            'loan_type_id' => [
                'required',
                'integer',
                Rule::exists('loan_types', 'id'),
            ],

            'loan_reference_no' => [
                'required',
                'string',
                'max:100',
                Rule::unique(
                    'employee_loans',
                    'loan_reference_no'
                )->ignore($loan?->id),
            ],

            'principal_amount' => [
                'required', 'numeric', 'min:0.01',
            ],

            'total_payable_amount' => [
                'required', 'numeric', 'gte:principal_amount',
            ],

            'deduction_amount' => [
                'required', 'numeric',
                'min:0.01',
                'lte:total_payable_amount',
            ],

            'loan_date' => [
                'required', 'date',
            ],

            'deduction_start_date' => [
                'required', 'date', 'after_or_equal:loan_date',
            ],

            'remarks' => [
                'nullable', 'string', 'max:2000',
            ],

            'status' => [
                'required', 'boolean',
            ],
        ];
    }

    private function formatLoan(EmployeeLoan $loan): array
    {
        $employee = $loan->employee;
        $type = $loan->loanType;

        $employeeName = $employee
            ? trim(implode(' ', array_filter([
                $employee->firstname ?? $employee->first_name ?? null,
                $employee->middlename ?? $employee->middle_name ?? null,
                $employee->lastname ?? $employee->last_name ?? null,
            ])))
            : null;

        return [
            'id' => $loan->id,

            'employee_id' => $loan->employee_id,
            'loan_type_id' => $loan->loan_type_id,

            'loan_reference_no' => $loan->loan_reference_no,

            'employee' => $employee ? [
                'id' => $employee->id,
                'emp_id' => $employee->emp_id,
                'name' => $employeeName ?: 'Unnamed Employee',
            ] : null,

            'loan_type' => $type ? [
                'id' => $type->id,
                'loan_code' => $type->loan_code,
                'loan_name' => $type->loan_name,
                'provider' => $type->provider,
            ] : null,

            'principal_amount' => $loan->principal_amount,
            'total_payable_amount' => $loan->total_payable_amount,
            'opening_balance' => $loan->opening_balance,
            'deduction_amount' => $loan->deduction_amount,

            'loan_date' => $loan->loan_date?->format('Y-m-d'),
            'deduction_start_date' =>
                $loan->deduction_start_date?->format('Y-m-d'),

            'remarks' => $loan->remarks,
            'status' => (bool) $loan->status,

            'created_by_name' => $loan->createdBy?->name,
            'created_at' => $loan->created_at?->toIso8601String(),
        ];
    }
}

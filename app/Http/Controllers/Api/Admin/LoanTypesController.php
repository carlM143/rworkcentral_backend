<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoanType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoanTypesController extends Controller
{
    public function index(): JsonResponse
    {
        $loanTypes = LoanType::query()
            ->select([
                'id',
                'loan_code',
                'loan_name',
                'provider',
                'status',
            ])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Loan types retrieved successfully.',
            'data' => $loanTypes,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'loan_code' => strtoupper(
                trim((string) $request->input('loan_code'))
            ),
        ]);

        $validated = $request->validate([
            'loan_code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('loan_types', 'loan_code'),
            ],
            'loan_name' => [
                'required',
                'string',
                'max:150',
            ],
            'provider' => [
                'required',
                'string',
                'max:150',
            ],
            'status' => [
                'required',
                'boolean',
            ],
        ]);

        $loanType = LoanType::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Loan type created successfully.',
            'data' => $loanType,
        ], 201);
    }

    public function edit(LoanType $loanType): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Loan type retrieved successfully.',
            'data' => $loanType,
        ]);
    }

    public function update(Request $request, LoanType $loanType): JsonResponse
    {
        $request->merge([
            'loan_code' => strtoupper(
                trim((string) $request->input('loan_code'))
            ),
        ]);

        $validated = $request->validate([
            'loan_code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('loan_types', 'loan_code')->ignore($loanType->id),
            ],
            'loan_name' => [
                'required',
                'string',
                'max:150',
            ],
            'provider' => [
                'required',
                'string',
                'max:150',
            ],
            'status' => [
                'required',
                'boolean',
            ],
        ]);

        $loanType->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Loan type updated successfully.',
            'data' => $loanType->refresh(),
        ]);
    }

    public function destroy(LoanType $loanType): JsonResponse
    {
        if ($loanType->employeeLoans()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This loan type cannot be deleted because it is already assigned to an employee loan.',
            ], 422);
        }

        $loanType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Loan type deleted successfully.',
        ]);
    }
}

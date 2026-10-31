<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    private const RELATIONS = [
        'admin:id,name,email',
    ];

    private function formatCompany(Company $company): array
    {
        $company->loadMissing(self::RELATIONS);
        $company->loadCount([
            'departments',
            'payrollPeriods',
            'contributionDeductionSettings',
        ]);

        $employeeCount = \App\Models\Employee::query()
            ->whereIn('department_id', $company->departments()->select('id'))
            ->count();

        $data = $company->toArray();
        $data['employees_count'] = $employeeCount;

        return $data;
    }

    public function index(Request $request): JsonResponse
    {
        $query = Company::query()
            ->with(self::RELATIONS)
            ->withCount(['departments', 'payrollPeriods']);

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $status = strtolower($request->string('status')->trim()->toString());
            $query->where('status', in_array($status, ['active', '1', 'true'], true));
        }

        $companies = $query->orderBy('company_name')->get();

        return response()->json([
            'success' => true,
            'message' => 'Companies retrieved successfully.',
            'data' => $companies,
        ]);
    }

    public function current(): JsonResponse
    {
        $company = Company::query()->first();

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'No company profile found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Current company profile retrieved successfully.',
            'data' => $this->formatCompany($company),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255', 'unique:companies,company_name'],
            'location' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'boolean'],
        ]);

        $company = Company::create([
            'admin_id' => $request->user()->id,
            'company_name' => $validated['company_name'],
            'location' => $validated['location'],
            'status' => $validated['status'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Company created successfully.',
            'data' => $this->formatCompany($company),
        ], 201);
    }

    public function show(Company $company): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Company profile retrieved successfully.',
            'data' => $this->formatCompany($company),
        ]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('companies', 'company_name')->ignore($company->id),
            ],
            'location' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'boolean'],
        ]);

        $company->update([
            'company_name' => $validated['company_name'],
            'location' => $validated['location'],
            'status' => $validated['status'] ?? $company->status,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Company information updated successfully.',
            'data' => $this->formatCompany($company->fresh()),
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        if (
            $company->departments()->exists() ||
            $company->payrollPeriods()->exists() ||
            $company->contributionDeductionSettings()->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this company because it has departments, payroll periods, or contribution settings associated with it.',
            ], 409);
        }

        $company->delete();

        return response()->json([
            'success' => true,
            'message' => 'Company deleted successfully.',
        ]);
    }
}

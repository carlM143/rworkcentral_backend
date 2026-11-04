<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    private const RELATIONS = [
        'admin:id,name,email',
        'locations',
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
                    ->orWhereHas('locations', fn ($location) => $location->where('location_name', 'like', "%{$search}%")->orWhere('city', 'like', "%{$search}%"));
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
        if (Company::query()->exists()) {
            return response()->json(['success' => false, 'message' => 'Only one company is allowed. Add locations to the existing company instead.'], 409);
        }
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255', 'unique:companies,company_name'],
            ...$this->locationRules(),
            'status' => ['nullable', 'boolean'],
        ]);

        $company = DB::transaction(function () use ($request, $validated) {
            $attributes = ['admin_id' => $request->user()->id, 'company_name' => $validated['company_name'], 'status' => $validated['status'] ?? true];
            $company = Company::create($attributes);
            $company->locations()->createMany($validated['locations']);
            return $company;
        });

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
            ...$this->locationRules(false),
            'status' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($company, $validated) {
            $company->update(['company_name' => $validated['company_name'], 'status' => $validated['status'] ?? $company->status]);
            $company->locations()->createMany($validated['locations'] ?? []);
        });

        return response()->json([
            'success' => true,
            'message' => 'Company information updated successfully.',
            'data' => $this->formatCompany($company->fresh()),
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        if (
            $company->locations()->exists() ||
            $company->departments()->exists() ||
            $company->payrollPeriods()->exists() ||
            $company->contributionDeductionSettings()->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this company because it has locations, departments, payroll periods, or contribution settings associated with it.',
            ], 409);
        }

        $company->delete();

        return response()->json([
            'success' => true,
            'message' => 'Company deleted successfully.',
        ]);
    }

    private function locationRules(bool $required = true): array
    {
        return [
            'locations' => [$required ? 'required' : 'sometimes', 'array', ...($required ? ['min:1'] : [])],
            'locations.*.location_name' => ['required', 'string', 'max:255', 'distinct', Rule::unique('locations', 'location_name')->where('company_id', request()->route('company')?->id)],
            'locations.*.city' => ['required', 'string', 'max:255'],
            'locations.*.province' => ['nullable', 'string', 'max:255'],
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    private function companyId(): int
    {
        return Company::query()->sole()->id;
    }

    private function departmentQuery()
    {
        return Department::query()
            ->where('company_id', $this->companyId())
            ->withCount(['positions', 'employments']);
    }

    // GET ALL
    public function index(Request $request)
    {
        $query = $this->departmentQuery();

        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->where('department_name', 'like', "%{$search}%")
                  ->orWhere('department_code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') || $request->input('status') === '0') {
            $request->validate([
                'status' => ['boolean'],
            ]);

            $query->where('status', $request->boolean('status'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Departments retrieved successfully.',
            'data' => $query->orderBy('department_name')->get(),
        ]);
    }

    // CREATE
    public function store(Request $request)
    {
        $companyId = $this->companyId();

        $request->merge([
            'department_code' => Str::upper(
                trim((string) $request->input('department_code', ''))
            ),
        ]);

        $validated = $request->validate([
            'department_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('departments', 'department_name')
                    ->where('company_id', $companyId),
            ],
            'department_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('departments', 'department_code')
                    ->where('company_id', $companyId),
            ],
            'status' => ['sometimes', 'boolean'],
        ]);

        $department = Department::create([
            'admin_id' => $request->user()->id,
            'company_id' => $companyId,
            'department_name' => $validated['department_name'],
            'department_code' => $validated['department_code'],
            'status' => $validated['status'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department created successfully.',
            'data' => $department->loadCount(['positions', 'employments']),
        ], 201);
    }

    // SHOW
    public function show(Department $department)
    {
        abort_unless(
            $department->company_id === $this->companyId(),
            404
        );

        return response()->json([
            'success' => true,
            'message' => 'Department retrieved successfully.',
            'data' => $department->loadCount(['positions', 'employments']),
        ]);
    }

    // UPDATE
    public function update(Request $request, Department $department)
    {
        abort_unless(
            $department->company_id === $this->companyId(),
            404
        );

        if ($request->exists('department_code')) {
            $request->merge([
                'department_code' => Str::upper(
                    trim((string) $request->input('department_code'))
                ),
            ]);
        }

        $validated = $request->validate([
            'department_name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('departments', 'department_name')
                    ->where('company_id', $department->company_id)
                    ->ignore($department->id),
            ],
            'department_code' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('departments', 'department_code')
                    ->where('company_id', $department->company_id)
                    ->ignore($department->id),
            ],
            'status' => ['sometimes', 'boolean'],
        ]);

        $department->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Department updated successfully.',
            'data' => $department->fresh()
                ->loadCount(['positions', 'employments']),
        ]);
    }

    // DELETE
    public function destroy(Department $department)
    {
        abort_unless(
            $department->company_id === $this->companyId(),
            404
        );

        if (
            $department->positions()->exists() ||
            $department->employments()->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a department with existing positions or employee assignments.',
            ], 409);
        }

        try {
            $department->delete();
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23503'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This department is referenced by existing records.',
                ], 409);
            }

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Department deleted successfully.',
        ]);
    }
}
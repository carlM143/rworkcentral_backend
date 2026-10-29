<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmploymentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmploymentTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmploymentType::query()->withCount('employments');

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();
            $query->where('name', 'like', "%{$search}%");
        }

        return response()->json([
            'success' => true,
            'message' => 'Employment types retrieved successfully.',
            'data' => $query->latest('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:employment_types,name'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'string'],
        ]);

        $employmentType = EmploymentType::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employment type created successfully.',
            'data' => $employmentType->loadCount('employments'),
        ], 201);
    }

    public function show(EmploymentType $employmentType): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Employment type retrieved successfully.',
            'data' => $employmentType->loadCount('employments'),
        ]);
    }

    public function update(Request $request, EmploymentType $employmentType): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('employment_types', 'name')->ignore($employmentType->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'string'],
        ]);

        $employmentType->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? $employmentType->description,
            'status' => $validated['status'] ?? $employmentType->status,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employment type updated successfully.',
            'data' => $employmentType->fresh()->loadCount('employments'),
        ]);
    }

    public function destroy(EmploymentType $employmentType): JsonResponse
    {
        if ($employmentType->employments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this employment type because it is assigned to existing employees.',
            ], 409);
        }

        $employmentType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Employment type deleted successfully.',
        ]);
    }
}

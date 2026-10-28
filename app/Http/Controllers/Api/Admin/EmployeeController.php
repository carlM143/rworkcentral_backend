<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmployeeRequest;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    private const LIST_RELATIONSHIPS = [
        'department',
        'user.role',
        'contactInformation',
        'employments.employmentType',
        'employments.department',
        'employments.position',
        'employments.workSchedule.scheduleType',
        'employments.restday',
    ];

    private const DETAIL_RELATIONSHIPS = [
        'department',
        'user.role',
        'contactInformation',
        'employments.employmentType',
        'employments.department',
        'employments.position',
        'employments.workSchedule.scheduleType',
        'employments.restday',

        // Government Requirements
        'governmentRequirements',

        // Employee Salary Setup
        'salarySetup.salaryType',

        // Historical Payroll Records
        'payrolls.salaryType',
        'payrolls.payrollGroup',

        // Emergency Contacts
        'contactPersons',

        // Employee Documents
        'documents.employmentDocument',
    ];

    public function __construct(
        private EmployeeService $employeeService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Employee::query()->with(self::LIST_RELATIONSHIPS);

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();

            $query->where(function ($query) use ($search) {
                $query->where('emp_id', 'like', "%{$search}%")
                    ->orWhere('firstname', 'like', "%{$search}%")
                    ->orWhere('middlename', 'like', "%{$search}%")
                    ->orWhere('lastname', 'like', "%{$search}%")
                    ->orWhereHas('contactInformation', function ($query) use ($search) {
                        $query->where('email', 'like', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('employment_type_id')) {
            $query->whereHas('employments', function ($query) use ($request) {
                $query->where('employment_type_id', $request->integer('employment_type_id'));
            });
        }

        if ($request->filled('position_id')) {
            $query->whereHas('employments', function ($query) use ($request) {
                $query->where('position_id', $request->integer('position_id'));
            });
        }

        if ($request->has('status') && $request->input('status') !== '') {
            $query->where('status', $request->boolean('status'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Employees retrieved successfully.',
            'data' => $query->latest('id')->get(),
        ]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employeeService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Employee created successfully.',
            'data' => [
                'id' => $employee->id,
                'emp_id' => $employee->emp_id,
                'firstname' => $employee->firstname,
                'lastname' => $employee->lastname,
            ],
        ], 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Employee retrieved successfully.',
            'data' => $employee->load(self::DETAIL_RELATIONSHIPS),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->employeeService->update($employee, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Employee updated successfully.',
            'data' => $employee->load(self::DETAIL_RELATIONSHIPS),
        ]);
    }

    public function deactivate(Employee $employee): JsonResponse
    {
        $employee->update(['status' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Employee deactivated successfully.',
            'data' => $employee->fresh()->load(self::LIST_RELATIONSHIPS),
        ]);
    }
}

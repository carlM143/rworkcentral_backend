<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\SalarySetup;
use App\Models\SalaryType;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

class EmployeeSalaryRateController extends Controller
{
    
public function index(): JsonResponse
{
    $employees = Employee::query()
            ->with([
                'department',
                'salarySetup.salaryType',

                // Do not restrict columns here.
                // latestOfMany() uses a SQL join.
                'latestEmployment.department',
                'latestEmployment.position',
            ])
            ->latest('id')
            ->get();

        $data = $employees->map(function (Employee $employee) {

            $salary = $employee->salarySetup;
            $employment = $employee->latestEmployment;

            $employeeName = trim(implode(' ', array_filter([
                $employee->firstname,
                $employee->middlename,
                $employee->lastname,
            ])));

            $departmentId =
                $employment?->department_id
                ?? $employee->department_id;

            $departmentName =
                $employment?->department?->department_name
                ?? $employee->department?->department_name
                ?? 'Not assigned';

            return [
                'id' => $salary?->id,

                'employee_id' => $employee->id,
                'emp_id' => $employee->emp_id,
                'employee_name' => $employeeName,

                'department_id' => $departmentId,
                'department_name' => $departmentName,

                'position_name' =>
                    $employment?->position?->position
                    ?? 'Not assigned',

                'salary_type_id' => $salary?->salary_type_id,

                'salary_type_name' =>
                    $salary?->salaryType?->type_name
                    ?? 'Not configured',

                'basic_salary' => $salary?->basic_salary,
                'allowance' => $salary?->allowance,

                'status' => (bool) $employee->status,

                'is_configured' => $salary !== null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Employee salary rates retrieved successfully.',
            'data' => $data,
        ]);
    }


    public function filterOptions(): JsonResponse
    {
        $departments = Department::query()
            ->orderBy('department_name')
            ->get(['id', 'department_name'])
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'name' => $item->department_name,
            ])
            ->values();

        $salaryTypes = SalaryType::query()
            ->orderBy('type_name')
            ->get(['id', 'type_name'])
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'name' => $item->type_name,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Salary rate filter options retrieved successfully.',
            'data' => [
                'departments' => $departments,
                'salary_types' => $salaryTypes,
            ],
        ]);
    }

    public function show(SalarySetup $salarySetup): JsonResponse
    {
        $salarySetup->load([
            'salaryType:id,type_name',
            'employee:id,emp_id,firstname,middlename,lastname,department_id,status',
            'employee.department:id,department_name',
            'employee.latestEmployment:id,employee_id,department_id,position_id,date_hired',
            'employee.latestEmployment.department:id,department_name',
            'employee.latestEmployment.position:id,position',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee salary rate retrieved successfully.',
            'data' => $this->formatRate($salarySetup),
        ]);
    }

    private function formatRate(SalarySetup $rate): array
    {
        $employee = $rate->employee;
        $employment = $employee?->latestEmployment;

        $employeeName = $employee
            ? trim(implode(' ', array_filter([
                $employee->firstname,
                $employee->middlename,
                $employee->lastname,
            ])))
            : 'Unknown Employee';

        $departmentId = $employment?->department_id
            ?? $employee?->department_id;

        $departmentName = $employment?->department?->department_name
            ?? $employee?->department?->department_name;

        return [
            'id' => $rate->id,

            'employee_id' => $rate->employee_id,
            'emp_id' => $employee?->emp_id,
            'employee_name' => $employeeName,

            'department_id' => $departmentId,
            'department_name' => $departmentName ?? 'Not assigned',

            'position_name' => $employment?->position?->position
                ?? 'Not assigned',

            'salary_type_id' => $rate->salary_type_id,
            'salary_type_name' => $rate->salaryType?->type_name
                ?? 'Not assigned',

            'basic_salary' => $rate->basic_salary,
            'allowance' => $rate->allowance,

            // Employee status, NOT salary rate approval status.
            'status' => (bool) ($employee?->status ?? false),
        ];
    }
}

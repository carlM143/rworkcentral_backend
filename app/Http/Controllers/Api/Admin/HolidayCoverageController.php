<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HolidayCoverageController extends Controller
{
    public function show(Holiday $holiday)
    {
        $company = Company::firstOrFail();
        abort_unless($holiday->company_id === $company->id && $holiday->status, 404);
        $employees = Employee::with(['department', 'employments.location'])
            ->whereHas('department', fn ($q) => $q->where('company_id', $company->id))
            ->orderBy('lastname')->orderBy('firstname')->get()->map(function ($employee) {
                $location = $employee->employments->sortByDesc('id')->first()?->location;
                return [
                    'id' => $employee->id, 'emp_id' => $employee->emp_id,
                    'name' => trim("{$employee->firstname} {$employee->lastname}"),
                    'department' => $employee->department?->department_name,
                    'location_id' => $location?->id, 'location' => $location?->location_name,
                    'status' => $employee->status,
                ];
            });
        return response()->json(['data' => ['holiday' => $holiday->load(['locations', 'coveredEmployees:id', 'holidayDates']), 'employees' => $employees]]);
    }

    public function update(Request $request, Holiday $holiday)
    {
        $company = Company::firstOrFail();
        abort_unless($holiday->company_id === $company->id && $holiday->status, 404);
        $data = $request->validate([
            'employee_coverage' => ['required', Rule::in(['all', 'except', 'only'])],
            'employee_ids' => ['present', 'array', Rule::requiredIf(fn () => $request->input('employee_coverage') === 'only')],
            'employee_ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')->whereIn('department_id', $company->departments()->select('id'))],
        ]);
        DB::transaction(function () use ($holiday, $data) {
            $holiday->employee_coverage = $data['employee_coverage'];
            $holiday->save();
            $holiday->coveredEmployees()->sync($data['employee_coverage'] === 'all' ? [] : $data['employee_ids']);
        });
        return response()->json(['data' => $holiday->load(['locations', 'coveredEmployees:id', 'holidayDates'])]);
    }
}

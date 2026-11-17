<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Which employees are paid for a holiday.
 *
 * Uses the existing holidays.employee_coverage column and holiday_employee_coverage pivot:
 *  - "all"  : everyone in the holiday's locations is paid
 *  - "only" : only the selected employees are paid (and only inside the holiday's locations)
 *
 * A legacy "except" holiday is shown as "only" with everyone except the excluded employees
 * ticked; saving the page stores it as "only".
 */
class HolidayCoverageController extends Controller
{
    private function company(Request $request): Company
    {
        abort_unless($request->user(), 401, 'Unauthenticated.');

        return $request->user()->company_id
            ? Company::query()->findOrFail($request->user()->company_id)
            : Company::query()->sole();
    }

    private function guard(Company $company, Holiday $holiday): void
    {
        abort_unless($holiday->company_id === $company->id && $holiday->status, 404);
    }

    private function dateOf(object $row): string
    {
        return substr((string) ($row->holiday_date ?? $row->holiday_dates ?? ''), 0, 10);
    }

    /** Earliest date of the holiday, preferring the given year. */
    private function pickDate(Holiday $holiday, ?int $year): ?string
    {
        $dates = $holiday->holidayDates->map(fn ($d) => $this->dateOf($d))->filter()->sort()->values();

        if ($year) {
            $inYear = $dates->first(fn ($d) => str_starts_with($d, (string) $year));

            if ($inYear) {
                return $inYear;
            }
        }

        return $dates->first();
    }

    private function payload(Company $company, Holiday $holiday, ?int $year = null): array
    {
        $holiday->load(['locations:id,location_name', 'holidayType:id,type_name', 'holidayDates']);

        $locationIds = $holiday->locations->pluck('id')->map(fn ($id) => (int) $id)->all();

        $mode = $holiday->employee_coverage === 'all' ? 'all' : 'only';

        $listed = $holiday->coveredEmployees()
            ->pluck('employees.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $employees = Employee::query()
            ->with([
                'department:id,department_name',
                'latestEmployment.location:id,location_name',
                'latestEmployment.employmentType',
            ])
            ->whereIn('department_id', $company->departments()->select('id'))
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get()
            ->map(function (Employee $employee) use ($locationIds, $mode, $listed) {
                $employment = $employee->latestEmployment;

                $location = $employment?->location;
                $employmentType = $employment?->employmentType;
                $inLocation = $locationIds === [] || ($location && in_array($location->id, $locationIds, true));
                $isListed = in_array($employee->id, $listed, true);

                $selected = $mode === 'all'
                ? $inLocation
                : $isListed;

                return [
                    'id' => $employee->id,
                    'emp_id' => $employee->emp_id,
                    'name' => trim("{$employee->firstname} {$employee->lastname}"),
                    'department_id' => $employee->department_id,
                    'department' => $employee->department?->department_name,
                    'employment_type' => $employmentType?->employment_type_name
                        ?? $employmentType?->type_name
                        ?? $employmentType?->name,
                    'location_id' => $location?->id,
                    'location' => $location?->location_name,
                    'status' => $employee->status,
                    'in_location' => (bool) $inLocation,
                    'selected' => (bool) $selected,
                ];
            });

        return [
            'holiday' => [
                'id' => $holiday->id,
                'holiday_name' => $holiday->holiday_name,
                'date' => $this->pickDate($holiday, $year),
                'type' => $holiday->holidayType?->type_name,
                'coverage_mode' => $mode === 'all' ? 'all' : 'only',
                'legacy_except' => $mode === 'except',
                'locations' => $holiday->locations->pluck('location_name')->values(),
            ],
            'employees' => $employees->values(),
        ];
    }

    
    public function index(Request $request): JsonResponse
    {
        $company = $this->company($request);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'location_id' => ['nullable'],
        ]);

        $year = (int) $data['year'];
        $month = $data['month'] ?? null;
        $locationId = $data['location_id'] ?? null;

        // Treat "all" as no location filter.
        if ($locationId === 'all') {
            $locationId = null;
        }

        $query = Holiday::query()
            ->where('company_id', $company->id)
            ->where('status', true)
            ->with('holidayDates');

        // Filter holidays applicable to the selected location.
        if ($locationId !== null) {
            $query->where(function ($q) use ($locationId) {
                $q->where('applies_to', 'all_locations')
                    ->orWhereHas('locations', function ($locationQuery) use ($locationId) {
                        $locationQuery->where('locations.id', $locationId);
                    });
            });
        }

        $holidays = $query->get()
            ->map(function (Holiday $holiday) use ($year) {
                $date = $holiday->holidayDates
                    ->map(fn ($d) => $this->dateOf($d))
                    ->filter(fn ($d) => $d !== '' && str_starts_with($d, (string) $year))
                    ->sort()
                    ->first();

                return $date
                    ? [
                        'id' => $holiday->id,
                        'holiday_name' => $holiday->holiday_name,
                        'date' => $date,
                        'applies_to' => $holiday->applies_to,
                    ]
                    : null;
            })
            ->filter()
            ->sortBy('date')
            ->values();

        $locations = Location::query()
            ->where('company_id', $company->id)
            ->where('status', true)
            ->orderBy('location_name')
            ->get(['id', 'location_name']);

        return response()->json([
            'success' => true,
            'data' => $holidays,
            'locations' => $locations,
            'selected_location_id' => $locationId ?? 'all',
        ]);
    }


    // GET /api/admin/holidays/{holiday}/coverage?year=2026
    public function show(Request $request, Holiday $holiday): JsonResponse
    {
        $company = $this->company($request);
        $this->guard($company, $holiday);

        return response()->json([
            'success' => true,
            'data' => $this->payload($company, $holiday, $request->integer('year') ?: null),
        ]);
    }

    // PUT /api/admin/holidays/{holiday}/coverage
    // Body: { employee_coverage: "all"|"only", employee_ids: [] }
    
    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $company = $this->company($request);

        $this->guard($company, $holiday);

        $data = $request->validate([
            'employee_coverage' => [
                'required',
                Rule::in(['all', 'only']),
            ],
            'employee_ids' => [
                'present',
                'array',
            ],
            'employee_ids.*' => [
                'integer',
                'distinct',
                'exists:employees,id',
            ],
        ]);

        if (
            $data['employee_coverage'] === 'only'
            && empty($data['employee_ids'])
        ) {
            throw ValidationException::withMessages([
                'employee_ids' => 'Select at least one employee, or choose Everyone.',
            ]);
        }

        $employeeIds = $data['employee_ids'];

        if ($data['employee_coverage'] === 'only') {

            // Only employees belonging to this company are allowed.
            $companyEmployeeIds = Employee::query()
                ->whereIn(
                    'department_id',
                    $company->departments()->select('id')
                )
                ->whereIn('id', $employeeIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (count($companyEmployeeIds) !== count($employeeIds)) {
                throw ValidationException::withMessages([
                    'employee_ids' => 'One or more employees do not belong to your company.',
                ]);
            }

            // Only employees assigned to the holiday's locations can be selected.
            $holiday->loadMissing('locations:id,location_name');

            $locationIds = $holiday->locations->pluck('id')->all();

            if (!empty($locationIds)) {
                $validEmployeeIds = Employee::query()
                    ->whereIn('id', $employeeIds)
                    ->whereHas('employments', function ($query) use ($locationIds) {
                        $query->whereIn('location_id', $locationIds);
                    })
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (count($validEmployeeIds) !== count($employeeIds)) {
                    throw ValidationException::withMessages([
                        'employee_ids' => 'Some selected employees are outside the holiday work locations.',
                    ]);
                }
            }
        }

        DB::transaction(function () use ($holiday, $data) {
            $mode = $data['employee_coverage'];

            $holiday->employee_coverage = $mode;
            $holiday->save();

            $holiday->coveredEmployees()->sync(
                $mode === 'all' ? [] : $data['employee_ids']
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Holiday coverage saved.',
            'data' => $this->payload(
                $company,
                $holiday->fresh(),
                $request->integer('year') ?: null
            ),
        ]);
    }

}
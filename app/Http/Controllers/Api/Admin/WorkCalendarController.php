<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\Employee;
use App\Models\TimeLog;
use Carbon\CarbonPeriod;
use App\Models\Holiday;
use App\Models\HolidayType;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\UndertimeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkCalendarController extends Controller
{
    public function holidays(Request $request)
    {
        $company = Company::firstOrFail();
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:1900', 'max:9999'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $company->id)],
        ]);
        $holidays = Holiday::with(['locations', 'holidayType', 'coveredEmployees:id', 'holidayDates'])->where('company_id', $company->id)->where('status', true)
            ->where(fn ($q) => $q->whereYear('holiday_date', $data['year'])->orWhereHas('holidayDates', fn ($dq) => $dq->whereYear('holiday_date', $data['year'])))
            ->when($data['location_id'] ?? null, fn ($q, $location) => $q->where(fn ($q) => $q->where('applies_to', 'all_locations')->orWhereHas('locations', fn ($q) => $q->where('locations.id', $location))))
            ->orderBy('holiday_date')->orderBy('id')->get();
        return response()->json(['data' => ['holidays' => $holidays, 'locations' => $company->locations, 'types' => HolidayType::where('status', true)->whereIn('type_code', ['REGULAR', 'SPECIAL', 'COMPANY', 'LOCAL'])->get()]]);
    }

    public function index(Request $request)
    {
        $company = Company::firstOrFail();
        $data = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $company->id)],
        ]);
        if (\Carbon\Carbon::parse($data['start'])->diffInDays($data['end']) > 62) {
            throw \Illuminate\Validation\ValidationException::withMessages(['end' => 'Select a range of at most 62 days.']);
        }
        $location = $data['location_id'] ?? null;
        $holidays = Holiday::with(['locations', 'holidayType', 'holidayDates'])->where('company_id', $company->id)
            ->where('status', true)
            ->where(fn ($q) => $q->whereBetween('holiday_date', [$data['start'], $data['end']])->orWhereHas('holidayDates', fn ($dq) => $dq->whereBetween('holiday_date', [$data['start'], $data['end']])))
            ->when($location, fn ($q) => $q->where(fn ($q) => $q->where('applies_to', 'all_locations')->orWhereHas('locations', fn ($q) => $q->where('locations.id', $location))))
            ->orderBy('holiday_date')->get();
        $leaves = LeaveRequest::with(['employee.employments.location', 'employee.department', 'leaveType', 'leaveDates' => fn ($q) => $q->whereBetween('leave_date', [$data['start'], $data['end']])])
            ->where('status', 'approved')
            ->whereHas('employee.department', fn ($q) => $q->where('company_id', $company->id))
            ->whereHas('leaveDates', fn ($q) => $q->whereBetween('leave_date', [$data['start'], $data['end']]))
            ->get()->filter(function ($leave) use ($location) {
                return ! $location || $leave->employee->employments->sortByDesc('id')->first()?->location_id == $location;
            })->flatMap(function ($leave) {
                $employee = $leave->employee;
                $employment = $employee->employments->sortByDesc('id')->first();
                return $leave->leaveDates->map(fn ($date) => [
                    'id' => 'leave-'.$date->id,
                    'filter_key' => 'leave:'.$leave->leave_type_id,
                    'category' => 'leave',
                    'date' => $date->leave_date->format('Y-m-d'),
                    'employee_id' => $employee->id,
                    'employee_name' => trim("{$employee->firstname} {$employee->lastname}"),
                    'department' => $employee->department?->department_name,
                    'location' => $employment?->location?->location_name,
                    'leave_type' => $leave->leaveType?->leave_name,
                    'day_type' => $date->day_type,
                    'day_value' => $date->day_value,
                ]);
            })->values();
        foreach ([['overtime', OvertimeRequest::class, 'ot_date', 'Overtime (OT)'], ['undertime', UndertimeRequest::class, 'undertime_date', 'Undertime']] as [$category, $model, $dateColumn, $label]) {
            $requests = $model::with(['employee.employments.location', 'employee.department'])
                ->where('status', 'approved')
                ->whereBetween($dateColumn, [$data['start'], $data['end']])
                ->whereHas('employee.department', fn ($q) => $q->where('company_id', $company->id))
                ->get()->filter(fn ($record) => ! $location || $record->employee->employments->sortByDesc('id')->first()?->location_id == $location)
                ->map(function ($record) use ($category, $dateColumn, $label) {
                    $employee = $record->employee;
                    $employment = $employee->employments->sortByDesc('id')->first();
                    $start = $category === 'overtime' ? $record->start_at : $record->requested_time_out;
                    $end = $category === 'overtime' ? $record->end_at : $record->scheduled_time_out;
                    return [
                        'id' => $category.'-'.$record->id,
                        'filter_key' => $category,
                        'category' => $category,
                        'date' => $record->{$dateColumn}->format('Y-m-d'),
                        'employee_id' => $employee->id,
                        'employee_name' => trim("{$employee->firstname} {$employee->lastname}"),
                        'department' => $employee->department?->department_name,
                        'location' => $employment?->location?->location_name,
                        'leave_type' => $label,
                        'time_label' => $start->format('M j, H:i').' – '.$end->format('M j, H:i'),
                    ];
                });
            $leaves = $leaves->concat($requests);
        }
        $requestTypes = LeaveType::orderBy('leave_name')->get()->map(fn ($type) => ['value' => 'leave:'.$type->id, 'label' => $type->leave_name])
            ->concat([['value' => 'overtime', 'label' => 'Overtime (OT)'], ['value' => 'undertime', 'label' => 'Undertime']]);
        $events = $this->employeeEvents($company, $data['start'], $data['end'], $location);
        $companyEvents = CompanyCalendarEvent::with('locations')->where('company_id', $company->id)
            ->whereBetween('event_date', [$data['start'], $data['end']])
            ->when($location, fn ($q) => $q->where(fn ($q) => $q->where('applies_to', 'all_locations')->orWhereHas('locations', fn ($q) => $q->where('locations.id', $location))))->orderBy('event_date')->get();
        return response()->json(['data' => ['holidays' => $holidays, 'company_events' => $companyEvents, 'leaves' => $leaves->values(), 'employee_events' => $events, 'request_types' => $requestTypes->values(), 'locations' => $company->locations, 'types' => HolidayType::where('status', true)->get()]]);
    }

    private function employeeEvents(Company $company, string $start, string $end, ?int $location)
    {
        $events = collect();
        $employees = Employee::with(['employments.location', 'department'])
            ->where('status', true)
            ->whereHas('department', fn ($q) => $q->where('company_id', $company->id))
            ->get()->filter(fn ($employee) => ! $location || $employee->employments->sortByDesc('id')->first()?->location_id == $location);

        foreach ($employees as $employee) {
            $employment = $employee->employments->sortByDesc('id')->first();
            foreach (CarbonPeriod::create($start, $end) as $date) {
                foreach (['birthday' => $employee->birthday, 'anniversary' => $employment?->date_hired] as $category => $sourceDate) {
                    // Match the actual month/day, including Feb 29 only in leap years.
                    if (! $sourceDate || $sourceDate->format('m-d') !== $date->format('m-d') || $sourceDate->year >= $date->year) {
                        continue;
                    }
                    $years = $date->year - $sourceDate->year;
                    $events->push([
                        ...$this->employeeEventDetails($employee),
                        'id' => $category.'-'.$employee->id.'-'.$date->format('Y-m-d'),
                        'date' => $date->format('Y-m-d'),
                        'category' => $category,
                        'leave_type' => $category === 'birthday' ? 'Birthday' : 'Work Anniversary',
                        'time_label' => $category === 'birthday' ? 'Birthday' : $years.' '.($years === 1 ? 'year' : 'years').' of service',
                    ]);
                }
            }
        }

        $logs = TimeLog::with(['employee.employments.location', 'employee.department'])
            ->whereBetween('work_date', [$start, $end])->whereNotNull('time_in')
            ->whereHas('employee.department', fn ($q) => $q->where('company_id', $company->id))
            ->whereRaw('COALESCE(raw_late_minutes, late_minutes, 0) > 0')
            ->get()->filter(fn ($log) => ! $location || $log->employee->employments->sortByDesc('id')->first()?->location_id == $location);
        foreach ($logs as $log) {
            $events->push([
                ...$this->employeeEventDetails($log->employee),
                'id' => 'late-'.$log->id,
                'date' => $log->work_date->format('Y-m-d'),
                'category' => 'late',
                'leave_type' => 'Late Attendance',
                'time_label' => ($log->raw_late_minutes ?? $log->late_minutes).' min recorded late · In '.$log->time_in->format('H:i'),
            ]);
        }
        return $events->sortBy('date')->values();
    }

    private function employeeEventDetails(Employee $employee): array
    {
        return [
            'employee_id' => $employee->id,
            'employee_name' => trim("{$employee->firstname} {$employee->lastname}"),
            'department' => $employee->department?->department_name,
            'location' => $employee->employments->sortByDesc('id')->first()?->location?->location_name,
        ];
    }

    public function store(Request $request)
    {
        return $this->save($request, new Holiday());
    }

    public function update(Request $request, Holiday $holiday)
    {
        abort_unless($holiday->company_id === Company::firstOrFail()->id, 404);
        return $this->save($request, $holiday);
    }

    private function save(Request $request, Holiday $holiday)
    {
        $company = Company::firstOrFail();
        $data = $request->validate([
            'holiday_name' => ['required', 'string', 'max:255'],
            'holiday_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:holiday_date'],
            'holiday_type_id' => ['required', 'integer', Rule::exists('holiday_types', 'id')->where(fn ($q) => $q->where(fn ($q) => $q->where('status', true)->whereIn('type_code', ['REGULAR', 'SPECIAL', 'COMPANY', 'LOCAL']))->when($holiday->exists, fn ($q) => $q->orWhere('id', $holiday->holiday_type_id)))],
            'is_working_day' => ['required', 'boolean'],
            'applies_to' => ['required', Rule::in(['all_locations', 'specific_locations'])],
            'location_ids' => ['required_if:applies_to,specific_locations', 'array'],
            'location_ids.*' => ['integer', 'distinct', Rule::exists('locations', 'id')->where('company_id', $company->id)],
        ]);
        $endDate = $data['end_date'] ?? $data['holiday_date'];
        if (\Carbon\Carbon::parse($data['holiday_date'])->diffInDays($endDate) > 62) {
            throw \Illuminate\Validation\ValidationException::withMessages(['end_date' => 'Select a holiday range of at most 62 days.']);
        }
        $created = ! $holiday->exists;
        DB::transaction(function () use ($request, $holiday, $company, $data, $endDate) {
            $holiday->fill(collect($data)->except(['location_ids', 'end_date'])->all());
            $holiday->company_id = $company->id;
            if (! $holiday->exists) $holiday->user_id = $request->user()->id;
            $holiday->save();
            $holiday->locations()->sync($data['applies_to'] === 'specific_locations' ? $data['location_ids'] : []);
            $holiday->holidayDates()->delete();
            foreach (CarbonPeriod::create($data['holiday_date'], $endDate) as $date) {
                $holiday->holidayDates()->create([
                    'holiday_date' => $date->format('Y-m-d'),
                ]);
            }
        });
        return response()->json(['data' => $holiday->load(['locations', 'holidayType', 'holidayDates'])], $created ? 201 : 200);
    }

    public function destroy(Holiday $holiday)
    {
        abort_unless($holiday->company_id === Company::firstOrFail()->id, 404);
        $holiday->update(['status' => false]);
        return response()->json(['message' => 'Calendar entry removed.']);
    }

    /**
     * Bulk import a year's worth of holidays.
     * Entries that already exist (same name and start date) are skipped.
     */
    public function import(Request $request)
    {
        $company = Company::firstOrFail();
        $allowedTypes = HolidayType::where('status', true)
            ->whereIn('type_code', ['REGULAR', 'SPECIAL', 'COMPANY', 'LOCAL'])
            ->pluck('id', 'type_code');

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:1900', 'max:9999'],
            'applies_to' => ['required', Rule::in(['all_locations', 'specific_locations'])],
            'location_ids' => ['required_if:applies_to,specific_locations', 'array'],
            'location_ids.*' => ['integer', 'distinct', Rule::exists('locations', 'id')->where('company_id', $company->id)],
            'holidays' => ['required', 'array', 'min:1', 'max:100'],
            'holidays.*.holiday_name' => ['required', 'string', 'max:255'],
            'holidays.*.holiday_date' => ['required', 'date_format:Y-m-d'],
            'holidays.*.end_date' => ['nullable', 'date_format:Y-m-d'],
            'holidays.*.holiday_type_id' => ['required', 'integer', Rule::in($allowedTypes->values()->all())],
            'holidays.*.is_working_day' => ['required', 'boolean'],
        ]);

        $errors = [];
        foreach ($data['holidays'] as $i => $item) {
            $start = \Carbon\Carbon::parse($item['holiday_date']);
            $end = \Carbon\Carbon::parse($item['end_date'] ?? $item['holiday_date']);
            if ((int) $start->year !== (int) $data['year']) {
                $errors["holidays.$i.holiday_date"] = "\"{$item['holiday_name']}\" is not within {$data['year']}.";
            } elseif ($end->lt($start)) {
                $errors["holidays.$i.end_date"] = "\"{$item['holiday_name']}\" end date is before its start date.";
            } elseif ($start->diffInDays($end) > 62) {
                $errors["holidays.$i.end_date"] = "\"{$item['holiday_name']}\" exceeds 62 days.";
            }
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        $created = 0;
        $skipped = [];

        DB::transaction(function () use ($request, $company, $data, &$created, &$skipped) {
            foreach ($data['holidays'] as $item) {
                $exists = Holiday::where('company_id', $company->id)
                    ->where('status', true)
                    ->whereDate('holiday_date', $item['holiday_date'])
                    ->whereRaw('LOWER(holiday_name) = ?', [mb_strtolower(trim($item['holiday_name']))])
                    ->exists();

                if ($exists) {
                    $skipped[] = $item['holiday_name'];
                    continue;
                }

                $holiday = Holiday::create([
                    'company_id' => $company->id,
                    'user_id' => $request->user()->id,
                    'holiday_name' => trim($item['holiday_name']),
                    'holiday_date' => $item['holiday_date'],
                    'holiday_type_id' => $item['holiday_type_id'],
                    'is_working_day' => $item['is_working_day'],
                    'applies_to' => $data['applies_to'],
                ]);
                $holiday->locations()->sync($data['applies_to'] === 'specific_locations' ? $data['location_ids'] : []);

                foreach (CarbonPeriod::create($item['holiday_date'], $item['end_date'] ?? $item['holiday_date']) as $date) {
                    $holiday->holidayDates()->create(['holiday_date' => $date->format('Y-m-d')]);
                }
                $created++;
            }
        });

        return response()->json([
            'message' => "{$created} holiday(s) imported for {$data['year']}." . (count($skipped) ? ' ' . count($skipped) . ' already existed and were skipped.' : ''),
            'data' => ['created' => $created, 'skipped' => $skipped],
        ], 201);
    }
}

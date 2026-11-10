<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\CompanyCalendarEvent;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeCalendarController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);
        if (Carbon::parse($data['start'])->diffInDays($data['end']) > 62) {
            throw ValidationException::withMessages(['end' => 'Select a range of at most 62 days.']);
        }
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['message' => 'No employee profile associated with this account.'], 404);
        }
        $employment = $employee->employments()->with('location')->latest('id')->first();
        $companyId = $employee->department?->company_id;
        $locationId = $employment?->location_id;
        $holidays = Holiday::with(['holidayType', 'holidayDates'])->where('company_id', $companyId)->where('status', true)
            ->where(fn ($q) => $q->whereBetween('holiday_date', [$data['start'], $data['end']])->orWhereHas('holidayDates', fn ($dq) => $dq->whereBetween('holiday_date', [$data['start'], $data['end']])))
            ->where(function ($query) use ($locationId) {
                $query->where('applies_to', 'all_locations');
                if ($locationId) {
                    $query->orWhereHas('locations', fn ($q) => $q->where('locations.id', $locationId));
                }
            })
            ->where(function ($query) use ($employee) {
                $query->where('employee_coverage', 'all')
                    ->orWhere(fn ($q) => $q->where('employee_coverage', 'only')->whereHas('coveredEmployees', fn ($q) => $q->where('employees.id', $employee->id)))
                    ->orWhere(fn ($q) => $q->where('employee_coverage', 'except')->whereDoesntHave('coveredEmployees', fn ($q) => $q->where('employees.id', $employee->id)));
            })->get()->flatMap(function ($holiday) use ($data) {
                $dates = $holiday->holidayDates->isNotEmpty()
                    ? $holiday->holidayDates->pluck('holiday_date')->map(fn ($d) => $d->format('Y-m-d'))
                    : collect([$holiday->holiday_date->format('Y-m-d')]);

                return $dates
                    ->filter(fn ($d) => $d >= $data['start'] && $d <= $data['end'])
                    ->values()
                    ->map(fn ($dateStr, $idx) => [
                        'id' => $dates->count() > 1 ? 'holiday-'.$holiday->id.'-'.$dateStr : 'holiday-'.$holiday->id,
                        'date' => $dateStr,
                        'category' => 'holiday',
                        'title' => $holiday->holiday_name,
                        'detail' => ($holiday->holidayType?->type_name ?? 'Holiday').' · '.($holiday->is_working_day ? 'Working day' : 'Non-working day'),
                    ]);
            });

        $events = $employee->leaveRequests()->with(['leaveType', 'leaveDates' => fn ($q) => $q->whereBetween('leave_date', [$data['start'], $data['end']])])
            ->where('status', 'approved')->whereHas('leaveDates', fn ($q) => $q->whereBetween('leave_date', [$data['start'], $data['end']]))
            ->get()->flatMap(fn ($leave) => $leave->leaveDates->map(fn ($date) => [
                'id' => 'leave-'.$date->id, 'date' => $date->leave_date->format('Y-m-d'), 'category' => 'leave',
                'title' => $leave->leaveType?->leave_name ?? 'Leave',
                'detail' => ((float) $date->day_value < 1 ? 'Half day' : 'Full day').' · Approved',
            ]));
        foreach ([['overtime', 'overtimeRequests', 'ot_date', 'start_at', 'end_at', 'Overtime'], ['undertime', 'undertimeRequests', 'undertime_date', 'requested_time_out', 'scheduled_time_out', 'Undertime']] as [$category, $relation, $dateColumn, $startColumn, $endColumn, $title]) {
            $records = $employee->{$relation}()->where('status', 'approved')->whereBetween($dateColumn, [$data['start'], $data['end']])->get();
            foreach ($records as $record) {
                $events->push([
                    'id' => $category.'-'.$record->id, 'date' => $record->{$dateColumn}->format('Y-m-d'),
                    'category' => $category, 'title' => $title,
                    'detail' => $record->{$startColumn}->format('M j, H:i').' – '.$record->{$endColumn}->format('M j, H:i').' · Approved',
                ]);
            }
        }
        $logs = $employee->timeLogs()->whereBetween('work_date', [$data['start'], $data['end']])->whereNotNull('time_in')
            ->whereRaw('COALESCE(raw_late_minutes, late_minutes, 0) > 0')->get();
        foreach ($logs as $log) {
            $events->push([
                'id' => 'late-'.$log->id, 'date' => $log->work_date->format('Y-m-d'), 'category' => 'late',
                'title' => 'Late Attendance', 'detail' => ($log->raw_late_minutes ?? $log->late_minutes).' min recorded late · In '.$log->time_in->format('H:i'),
            ]);
        }
        if ($employee->status) {
            foreach (CarbonPeriod::create($data['start'], $data['end']) as $date) {
                foreach (['birthday' => $employee->birthday, 'anniversary' => $employment?->date_hired] as $category => $source) {
                    if (! $source || $source->format('m-d') !== $date->format('m-d') || $source->year >= $date->year) continue;
                    $years = $date->year - $source->year;
                    $events->push([
                        'id' => $category.'-'.$date->format('Y-m-d'), 'date' => $date->format('Y-m-d'), 'category' => $category,
                        'title' => $category === 'birthday' ? 'Your Birthday' : 'Work Anniversary',
                        'detail' => $category === 'birthday' ? 'Happy birthday!' : $years.' '.($years === 1 ? 'year' : 'years').' of service',
                    ]);
                }
            }
        }
        $companyEvents = CompanyCalendarEvent::where('company_id', $companyId)->whereBetween('event_date', [$data['start'], $data['end']])
            ->where(function ($query) use ($locationId) {
                $query->where('applies_to', 'all_locations');
                if ($locationId) $query->orWhereHas('locations', fn ($q) => $q->where('locations.id', $locationId));
            })->get()->map(fn ($event) => [
                'id' => 'company-event-'.$event->id, 'date' => $event->event_date->format('Y-m-d'),
                'category' => 'company_event', 'title' => $event->name,
                'detail' => collect([$event->event_time ? substr($event->event_time, 0, 5) : null, $event->description])->filter()->join(' · '),
            ]);
        return response()->json(['data' => [
            'events' => $holidays->concat($events)->concat($companyEvents)->sortBy('date')->values(),
            'location' => $employment?->location ? ['name' => $employment->location->location_name, 'city' => $employment->location->city] : null,
        ]]);
    }
}

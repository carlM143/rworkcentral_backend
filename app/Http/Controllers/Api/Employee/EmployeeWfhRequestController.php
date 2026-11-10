<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Api\Admin\WfhDocumentController as AdminWfhDocumentController;
use App\Http\Controllers\Api\Admin\WfhRequestController as AdminWfhRequestController;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TimeLog;
use App\Models\WfhTracker;
use App\Models\WorkFromHome;
use App\Services\WfhScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeWfhRequestController extends Controller
{
    public function __construct(
        private readonly AdminWfhRequestController $requests,
        private readonly AdminWfhDocumentController $documents,
        private readonly WfhScheduleService $schedule,
    ) {}

    private function employee(Request $request): Employee
    {
        return $request->user()->employee ?? abort(404, 'No employee profile associated with this account.');
    }

    public function index(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $employee->load([
            'latestEmployment.workSchedule.days',
            'employments.workSchedule.days',
        ]);

        $records = WorkFromHome::where('employee_id', $employee->id)
            ->with([
                'approvedBy:id,name',
                'trackers' => fn ($query) => $query->orderBy('date_attendance'),
                'trackers.images',
                'trackers.approvedBy:id,name',
            ])
            ->latest('id')
            ->get()
            ->map(function ($record) use ($employee) {
                $record->setRelation('employee', $employee);
                $details = $this->schedule->scheduleBreakdown($record);
                $docSummary = $this->documents->formatSummary($record);
                $days = $this->documents->formatDays($record);

                return [
                    'id' => $record->id,
                    'request_no' => $record->request_no,
                    'start_date' => $record->start_date?->toDateString(),
                    'end_date' => $record->end_date?->toDateString(),
                    'number_of_days' => $record->number_of_days,
                    'calendar_days' => $details['calendar_days'],
                    'working_dates' => $details['working_dates'],
                    'breakdown' => $details['breakdown'],
                    'schedule_name' => $details['schedule_name'],
                    'schedule_code' => $details['schedule_code'],
                    'working_days' => $details['working_days'],
                    'rest_days' => $details['rest_days'],
                    'reason' => $record->reason,
                    'supervisor_name' => $record->supervisor_name,
                    'request_date' => $record->request_date?->toDateString(),
                    'status' => $record->status,
                    'remarks' => $record->remarks,
                    'approved_by' => $record->approvedBy?->name,
                    'approved_at' => $record->approved_at?->toDateString(),
                    'document_status' => $docSummary['document_status'],
                    'counts' => $docSummary['counts'],
                    'days' => $days,
                ];
            });
        return response()->json(['success' => true, 'data' => $records]);
    }

    /**
     * Allow the employee to upload daily proof of work for their approved WFH request.
     */
    public function attachDay(Request $request, WorkFromHome $workFromHome): JsonResponse
    {
        $employee = $this->employee($request);
        if ((int) $workFromHome->employee_id !== (int) $employee->id) {
            abort(403, 'You are not authorized to submit attachments for this WFH request.');
        }

        return $this->documents->attach($request, $workFromHome);
    }

    /**
     * Retrieve today's approved WFH status, time-in/time-out log, and proof-of-work tracker.
     */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $targetDate = $request->filled('date')
            ? Carbon::parse($request->input('date'))->toDateString()
            : Carbon::today()->toDateString();

        return response()->json([
            'success' => true,
            'data' => $this->buildTodayPayload($employee, $targetDate),
        ]);
    }

    /**
     * Record WFH Time In for today (only allowed when the employee has an approved WFH request covering today).
     */
    public function timeIn(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $employee->load([
            'latestEmployment.workSchedule.days',
            'employments.workSchedule.days',
        ]);

        $today = $request->filled('date')
            ? Carbon::parse($request->input('date'))->toDateString()
            : Carbon::today()->toDateString();

        $wfh = $this->findApprovedWfhForDate($employee, $today);

        if (! $wfh) {
            throw ValidationException::withMessages([
                'wfh' => 'Online Time In is only available when you have an approved Work From Home schedule for today.',
            ]);
        }

        $existingLog = TimeLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $today)
            ->first();

        if ($existingLog?->time_in) {
            throw ValidationException::withMessages([
                'time_in' => 'You have already timed in for today.',
            ]);
        }

        [$schedule, $scheduledIn, $scheduledOut] = $this->resolveShiftForDate($wfh, $today);
        $now = now();

        if ($existingLog) {
            $existingLog->update([
                'work_schedule_id' => $schedule?->id ?? $existingLog->work_schedule_id,
                'scheduled_time_in' => $scheduledIn,
                'scheduled_time_out' => $scheduledOut,
                'time_in' => $now,
                'source' => 'wfh',
                'status' => 'incomplete',
                'remarks' => "WFH Time In ({$wfh->request_no})",
            ]);
        } else {
            TimeLog::create([
                'employee_id' => $employee->id,
                'work_date' => $today,
                'work_schedule_id' => $schedule?->id,
                'scheduled_time_in' => $scheduledIn,
                'scheduled_time_out' => $scheduledOut,
                'time_in' => $now,
                'late_minutes' => 0,
                'raw_late_minutes' => 0,
                'grace_period_minutes' => (int) ($schedule?->grace_period ?? 0),
                'chargeable_late_minutes' => 0,
                'undertime_minutes' => 0,
                'worked_minutes' => 0,
                'overtime_minutes' => 0,
                'source' => 'wfh',
                'status' => 'incomplete',
                'remarks' => "WFH Time In ({$wfh->request_no})",
            ]);
        }

        WfhTracker::firstOrCreate(
            [
                'work_from_home_id' => $wfh->id,
                'date_attendance' => $today,
            ],
            [
                'employee_id' => $employee->id,
                'status' => 'draft',
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'WFH Time In recorded successfully.',
            'data' => $this->buildTodayPayload($employee, $today),
        ]);
    }

    /**
     * Record WFH Time Out for today and attach live/uploaded Proof of Work images.
     */
    public function timeOut(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $employee->load([
            'latestEmployment.workSchedule.days',
            'employments.workSchedule.days',
        ]);

        $today = $request->filled('date')
            ? Carbon::parse($request->input('date'))->toDateString()
            : Carbon::today()->toDateString();

        $wfh = $this->findApprovedWfhForDate($employee, $today);

        if (! $wfh) {
            throw ValidationException::withMessages([
                'wfh' => 'Online Time Out is only available when you have an approved Work From Home schedule for today.',
            ]);
        }

        $timeLog = TimeLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $today)
            ->first();

        if (! $timeLog?->time_in) {
            throw ValidationException::withMessages([
                'time_in' => 'Please Time In first before timing out.',
            ]);
        }

        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:4096'],
        ]);

        // Attach the proof-of-work images to the WFH tracker for today
        $request->merge(['date_attendance' => $today]);
        $this->documents->attach($request, $wfh);

        $now = $timeLog->time_out ?? now();
        if ($now->lessThan($timeLog->time_in)) {
            $now = $timeLog->time_in->copy()->addMinute();
        }

        [$schedule, $scheduledIn, $scheduledOut] = $this->resolveShiftForDate($wfh, $today);
        $rawMinutes = max(1, (int) $timeLog->time_in->diffInMinutes($now));
        $workedMinutes = $rawMinutes >= 480 ? $rawMinutes - 60 : $rawMinutes;

        $timeLog->update([
            'work_schedule_id' => $schedule?->id ?? $timeLog->work_schedule_id,
            'scheduled_time_in' => $timeLog->scheduled_time_in ?? $scheduledIn,
            'scheduled_time_out' => $timeLog->scheduled_time_out ?? $scheduledOut,
            'time_out' => $now,
            'worked_minutes' => $workedMinutes,
            'source' => 'wfh',
            'remarks' => "WFH Time Out · Proof of Work Submitted ({$wfh->request_no})",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'WFH Time Out recorded and Proof of Work submitted for Admin verification.',
            'data' => $this->buildTodayPayload($employee, $today),
        ]);
    }

    private function findApprovedWfhForDate(Employee $employee, string $date): ?WorkFromHome
    {
        $candidates = WorkFromHome::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->with([
                'approvedBy:id,name',
                'trackers' => fn ($q) => $q->orderBy('date_attendance'),
                'trackers.images',
                'trackers.approvedBy:id,name',
            ])
            ->latest('id')
            ->get();

        foreach ($candidates as $wfh) {
            $wfh->setRelation('employee', $employee);
            if (in_array($date, $this->schedule->workingDates($wfh), true)) {
                return $wfh;
            }
        }

        return null;
    }

    /**
     * @return array{0: mixed, 1: Carbon, 2: Carbon}
     */
    private function resolveShiftForDate(WorkFromHome $wfh, string $workDate): array
    {
        $schedule = $this->schedule->getWorkSchedule($wfh);
        $dayPrefix = strtolower(substr(Carbon::parse($workDate)->format('l'), 0, 3));

        $scheduleDay = $schedule?->days
            ?->first(fn ($d) => strtolower(substr(trim((string) $d->day_of_week), 0, 3)) === $dayPrefix);

        $timeInStr = $scheduleDay?->time_in ?: ($schedule?->time_in ?: '08:00:00');
        $timeOutStr = $scheduleDay?->time_out ?: ($schedule?->time_out ?: '17:00:00');

        $scheduledIn = Carbon::parse("{$workDate} {$timeInStr}");
        $scheduledOut = Carbon::parse("{$workDate} {$timeOutStr}");

        if ($scheduledOut->lessThanOrEqualTo($scheduledIn)) {
            $scheduledOut->addDay();
        }

        return [$schedule, $scheduledIn, $scheduledOut];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTodayPayload(Employee $employee, string $date): array
    {
        $employee->loadMissing([
            'latestEmployment.workSchedule.days',
            'employments.workSchedule.days',
        ]);

        $wfh = $this->findApprovedWfhForDate($employee, $date);
        $timeLog = TimeLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->first();

        $trackerData = null;
        $wfhData = null;

        if ($wfh) {
            $breakdown = $this->schedule->scheduleBreakdown($wfh);
            $daySchedule = collect($breakdown['breakdown'])
                ->first(fn (array $b): bool => $b['date'] === $date);

            $days = collect($this->documents->formatDays($wfh));
            $trackerData = $days->first(fn (array $d): bool => $d['date'] === $date);

            $wfhData = [
                'id' => $wfh->id,
                'request_no' => $wfh->request_no,
                'start_date' => $wfh->start_date?->toDateString(),
                'end_date' => $wfh->end_date?->toDateString(),
                'number_of_days' => $wfh->number_of_days,
                'supervisor_name' => $wfh->supervisor_name,
                'reason' => $wfh->reason,
                'schedule_name' => $breakdown['schedule_name'],
                'schedule_code' => $breakdown['schedule_code'],
                'schedule_time' => $daySchedule['schedule_time'] ?? null,
            ];
        }

        return [
            'has_approved_wfh' => $wfh !== null,
            'date' => $date,
            'wfh' => $wfhData,
            'time_log' => $timeLog ? [
                'id' => $timeLog->id,
                'work_date' => $timeLog->work_date?->toDateString(),
                'time_in' => $timeLog->time_in?->toIso8601String(),
                'time_out' => $timeLog->time_out?->toIso8601String(),
                'scheduled_time_in' => $timeLog->scheduled_time_in?->toIso8601String(),
                'scheduled_time_out' => $timeLog->scheduled_time_out?->toIso8601String(),
                'worked_minutes' => (int) $timeLog->worked_minutes,
                'hours_worked' => $timeLog->worked_minutes > 0 ? round($timeLog->worked_minutes / 60, 1) : 0,
                'status' => $timeLog->status,
                'source' => $timeLog->source,
                'remarks' => $timeLog->remarks,
            ] : null,
            'tracker' => $trackerData,
        ];
    }

    /**
     * Retrieve the current employee's assigned work schedule and rest days.
     */
    public function schedule(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $employee->load([
            'latestEmployment.workSchedule.days',
            'employments.workSchedule.days',
        ]);

        $draft = new WorkFromHome();
        $draft->setRelation('employee', $employee);

        $workSchedule = $this->schedule->getWorkSchedule($draft);
        $workingDays = $this->schedule->workingDayNames($draft);
        $allWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $restDays = $workingDays === [] ? [] : array_values(array_diff($allWeekdays, $workingDays));

        return response()->json([
            'success' => true,
            'data' => [
                'schedule_id' => $workSchedule?->id,
                'schedule_name' => $workSchedule?->schedule_name ?? $workSchedule?->schedule_code ?? 'Standard Schedule',
                'schedule_code' => $workSchedule?->schedule_code,
                'working_days' => $workingDays,
                'rest_days' => $restDays,
            ],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->merge(['employee_id' => $this->employee($request)->id]);
        return $this->requests->preview($request);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['employee_id' => $this->employee($request)->id, 'request_date' => now()->toDateString()]);
        return $this->requests->store($request);
    }
}

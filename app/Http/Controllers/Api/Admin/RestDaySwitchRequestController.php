<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Employment;
use App\Models\RestDaySwitchRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestDaySwitchRequestController extends Controller
{
    private const DAYS = [
        'Monday', 'Tuesday', 'Wednesday', 'Thursday',
        'Friday', 'Saturday', 'Sunday',
    ];

    private function weekday(string $value): string
    {
        foreach (self::DAYS as $day) {
            if (strcasecmp(trim($value), $day) === 0) {
                return $day;
            }
        }

        throw ValidationException::withMessages([
            'requested_rest_day' => 'Invalid day of the week.',
        ]);
    }

    
    private function currentRestDay(Employment $employment): string
    {
        if (!$employment->work_schedule_id) {
            throw ValidationException::withMessages([
                'employee_id' => 'Employee has no assigned work schedule.',
            ]);
        }

        $normalizeDay = function (string $value): ?string {
            $days = [
                'mon' => 'Monday',
                'tue' => 'Tuesday',
                'wed' => 'Wednesday',
                'thu' => 'Thursday',
                'fri' => 'Friday',
                'sat' => 'Saturday',
                'sun' => 'Sunday',
            ];

            return $days[strtolower(substr(trim($value), 0, 3))] ?? null;
        };

        $scheduleDays = DB::table('work_schedule_days')
            ->where('work_schedule_id', $employment->work_schedule_id)
            ->get(['day_of_week', 'is_working_day']);

        if ($scheduleDays->isEmpty()) {
            throw ValidationException::withMessages([
                'employee_id' => 'Work schedule days have not been configured.',
            ]);
        }

        $configured = [];

        foreach ($scheduleDays as $day) {
            $normalized = $normalizeDay((string) $day->day_of_week);

            if ($normalized === null) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Invalid weekday found in work schedule configuration.',
                ]);
            }

            if (isset($configured[$normalized])) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Duplicate weekday found in work schedule configuration.',
                ]);
            }

            $configured[$normalized] = (bool) $day->is_working_day;
        }

        $restDays = [];

        foreach (self::DAYS as $day) {
            if (!array_key_exists($day, $configured)) {
                // Existing schedule uses missing weekdays as rest days.
                $restDays[] = $day;
            } elseif ($configured[$day] === false) {
                $restDays[] = $day;
            }
        }

        if (count($restDays) !== 1) {
            throw ValidationException::withMessages([
                'employee_id' =>
                    'Schedule must have exactly one configured or implied rest day.',
            ]);
        }

        return $restDays[0];
    }

    private function employmentFor(
        int $employeeId,
        string $effectiveDate
    ): Employment {
        $employment = Employment::query()
            ->with('workSchedule')
            ->where('employee_id', $employeeId)
            ->whereDate('date_hired', '<=', $effectiveDate)
            ->orderByDesc('date_hired')
            ->orderByDesc('id')
            ->first();

        if (!$employment) {
            throw ValidationException::withMessages([
                'employee_id' => 'No employment record exists for this date.',
            ]);
        }

        if (!$employment->workSchedule) {
            throw ValidationException::withMessages([
                'employee_id' => 'The employee has no assigned work schedule.',
            ]);
        }

        return $employment;
    }

    private function datesFor(
        string $effectiveDate,
        string $originalRestDay,
        string $requestedRestDay
    ): array {
        $effective = CarbonImmutable::parse($effectiveDate);

        if ($effective->format('l') !== $requestedRestDay) {
            throw ValidationException::withMessages([
                'effective_date' =>
                    'Effective date must fall on the requested rest day.',
            ]);
        }

        $weekStart = $effective->startOfWeek(
            CarbonImmutable::SUNDAY
        );

        $weekEnd = $weekStart->addDays(6);

        $dayIndex = array_search(
            $originalRestDay,
            self::DAYS,
            true
        );

        // DAYS starts Monday; weekStart starts Sunday.
        $offset = $dayIndex === 6 ? 0 : $dayIndex + 1;

        $originalDate = $weekStart->addDays($offset);

        return [
            'original_rest_date' => $originalDate->toDateString(),
            'effective_date' => $effective->toDateString(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
        ];
    }

    private function contextFor(
        int $employeeId,
        string $effectiveDate,
        string $requestedRestDay
    ): array {
        $employee = Employee::findOrFail($employeeId);

        $employment = $this->employmentFor(
            $employeeId,
            $effectiveDate
        );

        $original = $this->currentRestDay($employment);

        
        $workingDay = DB::table('work_schedule_days')
            ->where('work_schedule_id', $employment->work_schedule_id)
            ->where('is_working_day', 1)
            ->get(['day_of_week'])
            ->contains(function ($row) use ($requestedRestDay) {
                return strtolower(
                    substr(trim($row->day_of_week), 0, 3)
                ) === strtolower(
                    substr(trim($requestedRestDay), 0, 3)
                );
            });

        if (!$workingDay) {
            throw ValidationException::withMessages([
                'requested_rest_day' =>
                    'Requested rest day must be an existing working day.',
            ]);
        }

        if ($original === $requestedRestDay) {
            throw ValidationException::withMessages([
                'requested_rest_day' =>
                    'Requested rest day must differ from the current rest day.',
            ]);
        }

        $schedule = $employment->workSchedule;

        $scheduleLabel = $schedule->schedule_name ?? 'Not recorded';
        
        return [
            'employee' => $employee,
            'employment' => $employment,
            'schedule_label' => $scheduleLabel,
            'original' => $original,
            'dates' => $this->datesFor(
                $effectiveDate,
                $original,
                $requestedRestDay
            ),
        ];
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'requested_rest_day' => [
                'required', 'string',
                'in:' . implode(',', self::DAYS),
            ],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
    }

    private function ensureNoConflict(
        int $employeeId,
        string $weekStart,
        ?int $exceptId = null
    ): void {
        $conflict = RestDaySwitchRequest::query()
            ->where('employee_id', $employeeId)
            ->whereDate('week_start', $weekStart)
            ->whereIn('status', ['pending', 'approved'])
            ->when(
                $exceptId !== null,
                fn ($query) => $query->where('id', '!=', $exceptId)
            )
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'effective_date' =>
                    'This employee already has a Pending or Approved rest day switch request for the selected week. Please choose a different effective week.',
            ]);
        }
    }

    private function formatRecord(RestDaySwitchRequest $record): array
    {
        
        $record->loadMissing([
            'employee.employments.department',
            'employee.employments.position',
            'requester',
            'reviewer',
            'canceller',
        ]);

        $employee = $record->employee;

        $name = trim(implode(' ', array_filter([
            $employee?->firstname,
            $employee?->middlename,
            $employee?->lastname,
        ])));

        $employment = $employee?->employments
            ?->sortByDesc('date_hired')
            ->first();

        $department = $employment?->department?->department_name
            ?? $employee?->department?->department_name
            ?? 'Not assigned';

        $position = $employment?->position?->position
            ?? 'Not assigned';

        $effective = $record->effective_date;

        $weekLabel =
            $record->week_start->format('M j') .
            ' - ' .
            $record->week_end->format('M j, Y');

        return [
            'id' => $record->id,
            'requestNumber' => $record->request_number,

            'employeeId' => $record->employee_id,
            'employeeNumber' => (string) ($employee?->emp_id ?? ''),
            'employeeName' => $name,
            'department' => $department,
            'position' => $position,

            'currentSchedule' => $record->current_schedule,
            'currentRestDay' => $record->current_rest_day,
            'requestedRestDay' => $record->requested_rest_day,

            'originalRestDate' =>
                $record->original_rest_date->format('Y-m-d'),

            'effectiveDate' => $effective->format('Y-m-d'),
            'effectiveWeek' => $weekLabel,

            'reason' => $record->reason,

            'status' => ucfirst($record->status),

            'requestedBy' => $record->requester?->name ?? 'Unknown',
            'requestedAt' => $record->created_at?->toIso8601String(),

            'approvedBy' => $record->status === 'approved'
                ? $record->reviewer?->name : null,
            'approvedAt' => $record->status === 'approved'
                ? $record->reviewed_at?->toIso8601String() : null,
            'approvalNotes' => $record->status === 'approved'
                ? $record->review_remarks : null,

            'rejectedBy' => $record->status === 'rejected'
                ? $record->reviewer?->name : null,
            'rejectedAt' => $record->status === 'rejected'
                ? $record->reviewed_at?->toIso8601String() : null,
            'rejectionReason' => $record->status === 'rejected'
                ? $record->review_remarks : null,

            'cancelledBy' => $record->canceller?->name,
            'cancelledAt' => $record->cancelled_at?->toIso8601String(),
            'cancellationReason' => $record->cancellation_reason,
        ];
    }

    private function responseRecord(
        RestDaySwitchRequest $record,
        string $message
    ) {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->formatRecord($record),
        ]);
    }

    public function index(Request $request)
    {
        $query = RestDaySwitchRequest::query()
            ->with([
                'employee.department',
                'requester',
                'reviewer',
                'canceller',
            ])
            ->latest('id');

        $records = $query->get()->map(
            fn ($record) => $this->formatRecord($record)
        );

        return response()->json([
            'success' => true,
            'data' => $records,
        ]);
    }

    public function show(RestDaySwitchRequest $restDaySwitchRequest)
    {
        return $this->responseRecord(
            $restDaySwitchRequest,
            'Request retrieved successfully.'
        );
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $day = $this->weekday($data['requested_rest_day']);

        $record = DB::transaction(function () use (
            $request,
            $data,
            $day
        ) {
            // Lock employee so concurrent submissions cannot
            // create two active requests for the same week.
            Employee::whereKey($data['employee_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $context = $this->contextFor(
                $data['employee_id'],
                $data['effective_date'],
                $day
            );

            $dates = $context['dates'];

            $this->ensureNoConflict(
                $data['employee_id'],
                $dates['week_start']
            );

            $record = RestDaySwitchRequest::create([
                'request_number' => 'TMP-' . bin2hex(random_bytes(10)),
                'employee_id' => $data['employee_id'],
                'employment_id' => $context['employment']->id,
                'work_schedule_id' =>
                    $context['employment']->work_schedule_id,

                'current_schedule' => $context['schedule_label'],
                'current_rest_day' => $context['original'],
                'requested_rest_day' => $day,

                ...$dates,

                'reason' => trim($data['reason']),
                'status' => 'pending',
                'requested_by' => $request->user()->id,
            ]);

            $record->update([
                'request_number' =>
                    'SRD-' . now()->format('Y') . '-' .
                    str_pad((string) $record->id, 5, '0', STR_PAD_LEFT),
            ]);

            return $record;
        });

        return $this->responseRecord(
            $record,
            'Rest day switch request submitted successfully.'
        )->setStatusCode(201);
    }

    public function update(
        Request $request,
        RestDaySwitchRequest $restDaySwitchRequest
    ) {
        $data = $this->validatePayload($request);

        $record = DB::transaction(function () use (
            $restDaySwitchRequest,
            $data
        ) {
            Employee::whereKey($restDaySwitchRequest->employee_id)
                ->lockForUpdate()
                ->firstOrFail();

            $record = RestDaySwitchRequest::whereKey(
                $restDaySwitchRequest->id
            )->lockForUpdate()->firstOrFail();

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending requests can be edited.',
                ]);
            }

            if ((int) $data['employee_id'] !== (int) $record->employee_id) {
                throw ValidationException::withMessages([
                    'employee_id' =>
                        'The employee cannot be changed on an existing request.',
                ]);
            }

            $day = $this->weekday($data['requested_rest_day']);

            $context = $this->contextFor(
                $record->employee_id,
                $data['effective_date'],
                $day
            );

            $dates = $context['dates'];

            $this->ensureNoConflict(
                $record->employee_id,
                $dates['week_start'],
                $record->id
            );

            $record->update([
                'employment_id' => $context['employment']->id,
                'work_schedule_id' =>
                    $context['employment']->work_schedule_id,

                'current_schedule' => $context['schedule_label'],
                'current_rest_day' => $context['original'],
                'requested_rest_day' => $day,

                ...$dates,

                'reason' => trim($data['reason']),
            ]);

            return $record;
        });

        return $this->responseRecord(
            $record,
            'Rest day switch request updated successfully.'
        );
    }

    public function approve(
        Request $request,
        RestDaySwitchRequest $restDaySwitchRequest
    ) {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $record = DB::transaction(function () use (
            $request,
            $restDaySwitchRequest,
            $data
        ) {
            $record = RestDaySwitchRequest::whereKey(
                $restDaySwitchRequest->id
            )->lockForUpdate()->firstOrFail();

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending requests can be approved.',
                ]);
            }

            $record->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_remarks' => $data['notes'] ?? null,
            ]);

            return $record;
        });

        return $this->responseRecord(
            $record,
            'Rest day switch request approved successfully.'
        );
    }

    public function reject(
        Request $request,
        RestDaySwitchRequest $restDaySwitchRequest
    ) {
        $data = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        $record = DB::transaction(function () use (
            $request,
            $restDaySwitchRequest,
            $data
        ) {
            $record = RestDaySwitchRequest::whereKey(
                $restDaySwitchRequest->id
            )->lockForUpdate()->firstOrFail();

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending requests can be rejected.',
                ]);
            }

            $record->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_remarks' => trim($data['notes']),
            ]);

            return $record;
        });

        return $this->responseRecord(
            $record,
            'Rest day switch request rejected successfully.'
        );
    }

    public function cancel(
        Request $request,
        RestDaySwitchRequest $restDaySwitchRequest
    ) {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $record = DB::transaction(function () use (
            $request,
            $restDaySwitchRequest,
            $data
        ) {
            $record = RestDaySwitchRequest::whereKey(
                $restDaySwitchRequest->id
            )->lockForUpdate()->firstOrFail();

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending requests can be cancelled.',
                ]);
            }

            $record->update([
                'status' => 'cancelled',
                'cancelled_by' => $request->user()->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $data['notes'] ?? null,
            ]);

            return $record;
        });

        return $this->responseRecord(
            $record,
            'Rest day switch request cancelled successfully.'
        );
    }
}

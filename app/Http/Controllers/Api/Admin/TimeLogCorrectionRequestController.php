<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TimeLog;
use App\Models\TimeLogCorrectionRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Models\Employment;

class TimeLogCorrectionRequestController extends Controller
{
    
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdminTimekeepingView($request);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => [
                'nullable',
                Rule::in(['pending', 'approved', 'rejected']),
            ],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = TimeLogCorrectionRequest::query()
            ->with([
                'employee',
                'requester',
                'reviewer',
                'timeLog',
            ])
            ->latest();

        // Search employee name or employee number.
        if (!empty($data['search'])) {
            $keyword = trim($data['search']);

            $query->whereHas('employee', function ($employeeQuery) use ($keyword) {
                $employeeQuery->where(function ($q) use ($keyword) {
                    $q->where('firstname', 'like', "%{$keyword}%")
                        ->orWhere('lastname', 'like', "%{$keyword}%")
                        ->orWhere('emp_id', 'like', "%{$keyword}%")
                        ->orWhereRaw(
                            "CONCAT_WS(' ', firstname, lastname) LIKE ?",
                            ["%{$keyword}%"]
                        );
                });
            });
        }

        // Filter by request status.
        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        // Optional employee filter.
        if (!empty($data['employee_id'])) {
            $query->where('employee_id', $data['employee_id']);
        }

        // Dynamic statistics across all records.
        $stats = [
            'pending' => TimeLogCorrectionRequest::query()
                ->where('status', 'pending')
                ->count(),

            'approved' => TimeLogCorrectionRequest::query()
                ->where('status', 'approved')
                ->count(),

            'rejected' => TimeLogCorrectionRequest::query()
                ->where('status', 'rejected')
                ->count(),

            'this_month' => TimeLogCorrectionRequest::query()
                ->whereBetween('created_at', [
                    now()->startOfMonth(),
                    now()->endOfMonth(),
                ])
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Correction requests retrieved successfully.',
            'data' => $query->paginate(15),
            'stats' => $stats,
        ]);
    }

    public function history(TimeLog $timeLog): JsonResponse
    {
        $this->authorizeAdminTimekeepingView(request());

        $records = TimeLogCorrectionRequest::query()
            ->with(['requester', 'reviewer'])
            ->where('time_log_id', $timeLog->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $records,
        ]);
    }

    public function show(
        TimeLogCorrectionRequest $correction
    ): JsonResponse {
        $this->authorizeAdminTimekeepingView(request());

        return response()->json([
            'success' => true,
            'data' => $correction->load([
                'employee',
                'timeLog',
                'requester',
                'reviewer',
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdminPrivilege($request, 'correction.request');

        $data = $request->validate([
            'time_log_id' => [
                'required',
                'integer',
                'exists:time_logs,id',
            ],
            'correction_type' => [
                'required',
                Rule::in([
                    'time_in',
                    'time_out',
                    'time_in_out',
                    'break_times',
                ]),
            ],
            'priority' => [
                'required',
                Rule::in(['normal', 'urgent']),
            ],
            'requested_time_in' => [
                'nullable',
                'date_format:H:i',
                'required_if:correction_type,time_in,time_in_out',
            ],
            'requested_time_out' => [
                'nullable',
                'date_format:H:i',
                'required_if:correction_type,time_out,time_in_out',
            ],
            'requested_break_out' => [
                'nullable',
                'date_format:H:i',
                'required_if:correction_type,break_times',
            ],
            'requested_break_in' => [
                'nullable',
                'date_format:H:i',
                'required_if:correction_type,break_times',
            ],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'attachment' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],
        ]);

        $userId = $request->user()->id;
        $attachmentPath = null;

        $correction = DB::transaction(function () use (
            $request,
            $data,
            $userId,
            &$attachmentPath
        ) {
            $log = TimeLog::query()
                ->lockForUpdate()
                ->findOrFail($data['time_log_id']);

            if (
                TimeLogCorrectionRequest::query()
                    ->where('time_log_id', $log->id)
                    ->where('status', 'pending')
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'time_log_id' =>
                        'This time log already has a pending correction request.',
                ]);
            }

            $date = Carbon::parse($log->work_date)
                ->format('Y-m-d');

            $type = $data['correction_type'];

            $requestedIn = $log->time_in;
            $requestedOut = $log->time_out;
            $requestedBreakOut = $log->break_out;
            $requestedBreakIn = $log->break_in;

            $makeDateTime = function (?string $time) use ($date) {
                return $time ? "{$date} {$time}:00" : null;
            };

            if (in_array($type, ['time_in', 'time_in_out'])) {
                $requestedIn = $makeDateTime(
                    $data['requested_time_in'] ?? null
                );
            }

            if (in_array($type, ['time_out', 'time_in_out'])) {
                $requestedOut = $makeDateTime(
                    $data['requested_time_out'] ?? null
                );
            }

            if ($type === 'break_times') {
                $requestedBreakOut = $makeDateTime(
                    $data['requested_break_out'] ?? null
                );

                $requestedBreakIn = $makeDateTime(
                    $data['requested_break_in'] ?? null
                );
            }

            $this->validateIntervals(
                $requestedIn,
                $requestedOut,
                $requestedBreakOut,
                $requestedBreakIn
            );

            if ($request->hasFile('attachment')) {
                $attachmentPath = $request
                    ->file('attachment')
                    ->store('time-log-corrections', 'local');
            }

            return TimeLogCorrectionRequest::create([
                'time_log_id' => $log->id,
                'employee_id' => $log->employee_id,
                'requested_by' => $userId,
                'correction_type' => $type,
                'priority' => $data['priority'],
                'status' => 'pending',

                'original_time_in' => $log->time_in,
                'original_time_out' => $log->time_out,
                'original_break_out' => $log->break_out,
                'original_break_in' => $log->break_in,

                'requested_time_in' => $requestedIn,
                'requested_time_out' => $requestedOut,
                'requested_break_out' => $requestedBreakOut,
                'requested_break_in' => $requestedBreakIn,

                'reason' => $data['reason'],
                'attachment_path' => $attachmentPath,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Correction request submitted for review.',
            'data' => $correction,
        ], 201);
    }

    public function approve(
        Request $request,
        TimeLogCorrectionRequest $correction
    ): JsonResponse {
        $this->authorizeReviewer($request);

        $data = $request->validate([
            'review_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use (
            $request,
            $correction,
            $data
        ) {
            $log = TimeLog::query()
                ->lockForUpdate()
                ->findOrFail($correction->time_log_id);

            $item = TimeLogCorrectionRequest::query()
                ->lockForUpdate()
                ->findOrFail($correction->id);

            if ($item->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Request has already been reviewed.',
                ]);
            }

            $hasActiveLateAdjustment = $log->lateAdjustments()
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($hasActiveLateAdjustment) {
                throw ValidationException::withMessages([
                    'time_log_id' =>
                        'This attendance has a pending or approved Late Adjustment. ' .
                        'The late adjustment must be resolved or reconciled ' .
                        'before the attendance can be corrected.',
                ]);
            }

            if ((int) $item->requested_by === (int) $request->user()->id) {
                abort(403, 'You cannot approve your own request.');
            }

            // Prevent applying changes over a newer biometric
            // import or another attendance modification.
            foreach ([
                'time_in',
                'time_out',
                'break_out',
                'break_in',
            ] as $field) {
                $original = $item->{"original_{$field}"};
                $current = $log->{$field};

                if (
                    $this->timestamp($original) !==
                    $this->timestamp($current)
                ) {
                    throw ValidationException::withMessages([
                        'time_log_id' =>
                            'Attendance changed after this request was submitted. Review the latest time log first.',
                    ]);
                }
            }

           
$timeIn = $item->requested_time_in
    ? Carbon::parse($item->requested_time_in)
    : null;

$timeOut = $item->requested_time_out
    ? Carbon::parse($item->requested_time_out)
    : null;

$breakOut = $item->requested_break_out
    ? Carbon::parse($item->requested_break_out)
    : null;

$breakIn = $item->requested_break_in
    ? Carbon::parse($item->requested_break_in)
    : null;

$this->validateIntervals(
    $timeIn,
    $timeOut,
    $breakOut,
    $breakIn
);

$date = Carbon::parse($log->work_date)->format('Y-m-d');
$schedule = null;

// Use schedule data recorded during attendance import first.
$scheduledIn = $log->scheduled_time_in
    ? Carbon::parse($log->scheduled_time_in)
    : null;

$scheduledOut = $log->scheduled_time_out
    ? Carbon::parse($log->scheduled_time_out)
    : null;

$gracePeriod = $log->grace_period_minutes;

// Use assigned work schedule only as fallback.
if (!$scheduledIn || !$scheduledOut || $gracePeriod === null) {
    $employment = Employment::query()
        ->with('workSchedule')
        ->where('employee_id', $log->employee_id)
        ->whereDate('date_hired', '<=', $date)
        ->orderByDesc('date_hired')
        ->orderByDesc('id')
        ->first();

    $schedule = $employment?->workSchedule;

    if (!$schedule) {
        throw ValidationException::withMessages([
            'time_log_id' =>
                'No reliable work schedule was found for this attendance record.',
        ]);
    }

    $scheduledIn ??= Carbon::parse(
        $date . ' ' .
        Carbon::parse($schedule->time_in)->format('H:i:s')
    );

    $scheduledOut ??= Carbon::parse(
        $date . ' ' .
        Carbon::parse($schedule->time_out)->format('H:i:s')
    );

    $gracePeriod ??= (int) ($schedule->grace_period ?? 0);
    }

    $gracePeriod = max(0, (int) $gracePeriod);

    // Support shifts extending beyond midnight.
    if ($scheduledOut->lessThanOrEqualTo($scheduledIn)) {
        $scheduledOut->addDay();
    }

    // Calculate raw late and apply grace period.
    $rawLate = null;
    $graceAdjustedLate = null;

    if ($timeIn) {
        $rawLate = $timeIn->greaterThan($scheduledIn)
            ? (int) floor(
                $scheduledIn->diffInSeconds($timeIn) / 60
            )
            : 0;

        $graceAdjustedLate = max(
            0,
            $rawLate - $gracePeriod
        );
    }

    $undertime = null;

    if ($timeOut) {
        $undertime = $timeOut->lessThan($scheduledOut)
            ? (int) floor(
                $timeOut->diffInSeconds($scheduledOut) / 60
            )
            : 0;
    }

    // Calculate actual worked minutes.
    $worked = 0;

    if ($timeIn && $timeOut) {
        $worked = (int) floor(
            $timeIn->diffInSeconds($timeOut) / 60
        );

        if ($breakOut && $breakIn) {
            $worked -= (int) floor(
                $breakOut->diffInSeconds($breakIn) / 60
            );
        }

        $worked = max(0, $worked);
    }

    $status = !$timeIn && !$timeOut
        ? 'Missing'
        : (!$timeIn || !$timeOut
            ? 'Incomplete'
            : 'Complete');

    $log->update([
    'time_in' => $timeIn,
    'time_out' => $timeOut,
    'break_out' => $breakOut,
    'break_in' => $breakIn,

    'work_schedule_id' => $log->work_schedule_id
        ?? $schedule?->id
        ?? null,

    'scheduled_time_in' => $scheduledIn,
    'scheduled_time_out' => $scheduledOut,

    'raw_late_minutes' => $rawLate,
    'late_minutes' => $rawLate ?? 0,
    'grace_period_minutes' => $gracePeriod,
    'chargeable_late_minutes' => $graceAdjustedLate,

    'undertime_minutes' => $undertime ?? 0,
    'worked_minutes' => $worked,

    'status' => $status,
    'source' => 'approved_correction',
]);

            $item->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'review_remarks' => $data['review_remarks'] ?? null,
                'reviewed_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Correction approved and attendance updated.',
        ]);
    }

    public function reject(
        Request $request,
        TimeLogCorrectionRequest $correction
    ): JsonResponse {
        $this->authorizeReviewer($request);

        $data = $request->validate([
            'review_remarks' => [
                'required', 'string', 'min:5', 'max:2000',
            ],
        ]);

        DB::transaction(function () use (
            $correction, $request, $data
        ) {
            $item = TimeLogCorrectionRequest::query()
                ->lockForUpdate()
                ->findOrFail($correction->id);

            if ($item->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Request has already been reviewed.',
                ]);
            }

            if ((int) $item->requested_by === (int) $request->user()->id) {
                abort(403, 'You cannot reject your own request.');
            }

            $item->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'review_remarks' => $data['review_remarks'],
                'reviewed_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Correction request rejected.',
        ]);
    }

    private function timestamp($value): ?string
    {
        return $value
            ? Carbon::parse($value)->format('Y-m-d H:i:s')
            : null;
    }

    private function validateIntervals(
        $timeIn,
        $timeOut,
        $breakOut,
        $breakIn
    ): void {
        $in = $timeIn ? Carbon::parse($timeIn) : null;
        $out = $timeOut ? Carbon::parse($timeOut) : null;
        $bOut = $breakOut ? Carbon::parse($breakOut) : null;
        $bIn = $breakIn ? Carbon::parse($breakIn) : null;

        if ($in && $out && $out->lte($in)) {
            throw ValidationException::withMessages([
                'requested_time_out' =>
                    'Time Out must be later than Time In.',
            ]);
        }

        if (($bOut && !$bIn) || (!$bOut && $bIn)) {
            throw ValidationException::withMessages([
                'requested_break_in' =>
                    'Both break times must be provided.',
            ]);
        }

        if ($bOut && $bIn) {
            if (
                !$in || !$out ||
                $bIn->lte($bOut) ||
                $bOut->lt($in) ||
                $bIn->gt($out)
            ) {
                throw ValidationException::withMessages([
                    'requested_break_in' =>
                        'Break interval must fall inside the work interval.',
                ]);
            }
        }
    }

    /**
     * Admin API: only Administrator/HR can inspect all staff records.
     * Privileges still control the operation itself.
     */
    private function authorizeAdminPrivilege(Request $request, string $name): void
    {
        $user = $request->user();

        abort_unless(
            $user
            && in_array((int) $user->role_id, [1, 2], true)
            && $user->hasPrivilege('timekeeping', $name),
            403,
            'You are not authorized to perform this timekeeping action.'
        );
    }

    private function authorizeAdminTimekeepingView(Request $request): void
    {
        $this->authorizeAdminPrivilege($request, 'view');
    }

    private function authorizeReviewer(Request $request): void
    {
        $this->authorizeAdminPrivilege($request, 'correction.review');
    }
}

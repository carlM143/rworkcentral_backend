<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employment;
use App\Models\TimeLog;
use App\Models\TimeLogCorrectionRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TimeLogCorrectionRequestController extends Controller
{
    private const TYPES = ['time_in', 'time_out'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdminTimekeepingView($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'applied', 'rejected', 'cancelled'])],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = TimeLogCorrectionRequest::query()
            ->with(['employee', 'requester', 'reviewer', 'applier', 'timeLog'])
            ->latest('id');

        if (!empty($filters['search'])) {
            $keyword = trim($filters['search']);
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

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Correction requests retrieved successfully.',
            'data' => $query->paginate(15),
            'stats' => [
                'pending' => TimeLogCorrectionRequest::where('status', 'pending')->count(),
                'approved' => TimeLogCorrectionRequest::where('status', 'approved')->count(),
                'applied' => TimeLogCorrectionRequest::where('status', 'applied')->count(),
                'rejected' => TimeLogCorrectionRequest::where('status', 'rejected')->count(),
                'this_month' => TimeLogCorrectionRequest::whereBetween('created_at', [
                    now()->startOfMonth(), now()->endOfMonth(),
                ])->count(),
            ],
        ]);
    }

    public function history(TimeLog $timeLog): JsonResponse
    {
        $this->authorizeAdminTimekeepingView(request());

        return response()->json([
            'success' => true,
            'data' => TimeLogCorrectionRequest::query()
                ->with(['employee', 'requester', 'reviewer', 'applier', 'timeLog'])
                ->where('time_log_id', $timeLog->id)
                ->latest('id')
                ->get(),
        ]);
    }

    public function show(TimeLogCorrectionRequest $correction): JsonResponse
    {
        $this->authorizeAdminTimekeepingView(request());

        return response()->json([
            'success' => true,
            'data' => $correction->load([
                'employee', 'timeLog', 'requester', 'reviewer', 'applier',
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdminPrivilege($request, 'correction.request');

        $data = $request->validate([
            'time_log_id' => ['required', 'integer', 'exists:time_logs,id'],
            'correction_type' => ['required', Rule::in(self::TYPES)],
            'requested_time' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $path = null;

        try {
            $record = DB::transaction(function () use ($request, $data, &$path) {
                $log = TimeLog::query()
                    ->lockForUpdate()
                    ->findOrFail($data['time_log_id']);

                // An approved request still needs to be applied before another is filed.
                $hasActiveRequest = TimeLogCorrectionRequest::query()
                    ->where('time_log_id', $log->id)
                    ->whereIn('status', ['pending', 'approved'])
                    ->exists();

                if ($hasActiveRequest) {
                    throw ValidationException::withMessages([
                        'time_log_id' => [
                            'This time log already has a pending or approved (unapplied) correction.',
                        ],
                    ]);
                }

                if ($request->hasFile('attachment')) {
                    $path = $request->file('attachment')
                        ->store('time-log-corrections', 'local');
                }

                return TimeLogCorrectionRequest::create([
                    'time_log_id' => $log->id,
                    'employee_id' => $log->employee_id,
                    'requested_by' => $request->user()->getAuthIdentifier(),
                    'correction_type' => $data['correction_type'],
                    'status' => 'pending',
                    'original_time_in' => $log->time_in,
                    'original_time_out' => $log->time_out,
                    'requested_time' => $data['requested_time'] . ':00',
                    'reason' => trim($data['reason']),
                    'attachment_path' => $path,
                ]);
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Correction request submitted for review.',
            'data' => $record->load(['employee', 'requester', 'timeLog']),
        ], 201);
    }

    /** Step 1: approve only the request; do not update time_logs. */
    public function approve(Request $request, TimeLogCorrectionRequest $correction): JsonResponse
    {
        $this->authorizeReviewer($request);

        $data = $request->validate([
            'review_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $item = DB::transaction(function () use ($request, $correction, $data) {
            $item = TimeLogCorrectionRequest::query()
                ->lockForUpdate()
                ->findOrFail($correction->id);

            if ($item->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['Only pending correction requests can be approved.'],
                ]);
            }

            if ((int) $item->requested_by === (int) $request->user()->getAuthIdentifier()) {
                abort(403, 'You cannot approve your own request.');
            }

            // No time_logs mutation here.
            $item->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->getAuthIdentifier(),
                'review_remarks' => $data['review_remarks'] ?? null,
                'reviewed_at' => now(),
            ]);

            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => 'Correction approved. Click Apply to Time Log to update attendance.',
            'data' => $item->fresh(['employee', 'timeLog', 'requester', 'reviewer', 'applier']),
        ]);
    }

    /** Step 2: only an approved, unapplied request may change the time log. */
    public function apply(Request $request, TimeLogCorrectionRequest $correction): JsonResponse
    {
        $this->authorizeReviewer($request);

        $item = DB::transaction(function () use ($request, $correction) {
            // Lock time log first, then request (same order as store()).
            $log = TimeLog::query()
                ->lockForUpdate()
                ->findOrFail($correction->time_log_id);

            $item = TimeLogCorrectionRequest::query()
                ->lockForUpdate()
                ->findOrFail($correction->id);

            if ($item->status !== 'approved' || $item->applied_at !== null) {
                throw ValidationException::withMessages([
                    'status' => ['Only approved, unapplied corrections can be applied.'],
                ]);
            }

            if ((int) $item->requested_by === (int) $request->user()->getAuthIdentifier()) {
                abort(403, 'You cannot apply your own correction request.');
            }

            if ((int) $item->time_log_id !== (int) $log->id) {
                throw ValidationException::withMessages([
                    'time_log_id' => ['The correction no longer matches this time log.'],
                ]);
            }

            // Reject stale approvals: biometric imports or another correction may have changed the log.
            foreach (['time_in', 'time_out'] as $field) {
                if ($this->timestamp($item->{"original_{$field}"}) !== $this->timestamp($log->{$field})) {
                    throw ValidationException::withMessages([
                        'time_log_id' => [
                            'Attendance has changed since the correction was requested. '
                            . 'Review the latest time log before applying.',
                        ],
                    ]);
                }
            }

            $otherActive = TimeLogCorrectionRequest::query()
                ->where('time_log_id', $log->id)
                ->where('id', '!=', $item->id)
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($otherActive) {
                throw ValidationException::withMessages([
                    'time_log_id' => [
                        'Another correction is pending or approved for this time log. Resolve it first.',
                    ],
                ]);
            }

            // Intentionally no lateAdjustments() call: its foreign key is not time_log_id.
            // Reintroduce a conflict check only after confirming the actual LateAdjustment schema.
            $this->applyApprovedCorrectionToLog($log, $item);

            $item->update([
                'status' => 'applied',
                'applied_by' => $request->user()->getAuthIdentifier(),
                'applied_at' => now(),
            ]);

            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => 'Approved correction applied to the employee time log.',
            'data' => $item->fresh(['employee', 'timeLog', 'requester', 'reviewer', 'applier']),
        ]);
    }

    public function reject(Request $request, TimeLogCorrectionRequest $correction): JsonResponse
    {
        $this->authorizeReviewer($request);

        $data = $request->validate([
            'review_remarks' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $correction, $data) {
            $item = TimeLogCorrectionRequest::query()
                ->lockForUpdate()
                ->findOrFail($correction->id);

            if ($item->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['Only pending correction requests can be rejected.'],
                ]);
            }

            if ((int) $item->requested_by === (int) $request->user()->getAuthIdentifier()) {
                abort(403, 'You cannot reject your own request.');
            }

            $item->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->getAuthIdentifier(),
                'review_remarks' => trim($data['review_remarks']),
                'reviewed_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Correction request rejected.',
        ]);
    }

    private function applyApprovedCorrectionToLog(
        TimeLog $log,
        TimeLogCorrectionRequest $item
    ): void {
        $date = $log->work_date->format('Y-m-d');
        $schedule = null;

        $scheduledIn = $log->scheduled_time_in
            ? Carbon::parse($log->scheduled_time_in)
            : null;
        $scheduledOut = $log->scheduled_time_out
            ? Carbon::parse($log->scheduled_time_out)
            : null;

        if (!$scheduledIn || !$scheduledOut) {
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
                    'time_log_id' => ['No reliable work schedule was found for this attendance record.'],
                ]);
            }

            $scheduledIn ??= Carbon::parse(
                $date . ' ' . Carbon::parse($schedule->time_in)->format('H:i:s')
            );
            $scheduledOut ??= Carbon::parse(
                $date . ' ' . Carbon::parse($schedule->time_out)->format('H:i:s')
            );
        }

        $grace = max(0, (int) ($log->grace_period_minutes ?? $schedule?->grace_period ?? 0));

        if ($scheduledOut->lessThanOrEqualTo($scheduledIn)) {
            $scheduledOut->addDay();
        }

        $timeIn = $log->time_in ? Carbon::parse($log->time_in) : null;
        $timeOut = $log->time_out ? Carbon::parse($log->time_out) : null;
        $breakOut = $log->break_out ? Carbon::parse($log->break_out) : null;
        $breakIn = $log->break_in ? Carbon::parse($log->break_in) : null;

        $type = $item->correction_type;
        $clock = (string) $item->requested_time;

        if (!in_array($type, self::TYPES, true) ||
            !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $clock)) {
            throw ValidationException::withMessages([
                'requested_time' => ['The correction type or requested time is invalid.'],
            ]);
        }

        if (strlen($clock) === 5) {
            $clock .= ':00';
        }

        $requested = Carbon::parse($date . ' ' . $clock);

        if ($type === 'time_in') {
            // A time-only value cannot unambiguously represent a next-day Time In.
            if ($scheduledOut->toDateString() > $date && $requested->lessThan($scheduledIn)) {
                throw ValidationException::withMessages([
                    'requested_time' => [
                        'Overnight Time In may fall on the next calendar day. '
                        . 'This time-only request cannot specify that date; review it before applying.',
                    ],
                ]);
            }
            $timeIn = $requested;
        } else {
            $isOvernight = $scheduledOut->toDateString() > $date
                || ($timeOut && $timeOut->toDateString() > $date);
            $referenceIn = $timeIn ?? $scheduledIn;

            if ($isOvernight && $requested->lessThanOrEqualTo($referenceIn)) {
                $requested->addDay();
            }
            $timeOut = $requested;
        }

        $this->validateIntervals($timeIn, $timeOut, $breakOut, $breakIn);

        $rawLate = $timeIn
            ? max(0, (int) floor($scheduledIn->diffInSeconds($timeIn, false) / 60))
            : null;
        $chargeableLate = $rawLate === null ? null : max(0, $rawLate - $grace);
        $undertime = $timeOut
            ? max(0, (int) floor($timeOut->diffInSeconds($scheduledOut, false) / 60))
            : 0;

        $worked = 0;
        if ($timeIn && $timeOut) {
            $worked = (int) floor($timeIn->diffInSeconds($timeOut, false) / 60);
            if ($breakOut && $breakIn) {
                $worked -= (int) floor($breakOut->diffInSeconds($breakIn, false) / 60);
            }
            $worked = max(0, $worked);
        }

        $status = !$timeIn && !$timeOut
            ? 'Missing'
            : (!$timeIn || !$timeOut ? 'Incomplete' : 'Complete');

        // These fields change ONLY when Apply is called, never on Approve.
        $log->update([
            'time_in' => $timeIn,
            'time_out' => $timeOut,
            // Existing break_out and break_in are preserved.
            'work_schedule_id' => $log->work_schedule_id ?? $schedule?->id,
            'scheduled_time_in' => $scheduledIn,
            'scheduled_time_out' => $scheduledOut,
            'raw_late_minutes' => $rawLate,
            'late_minutes' => $rawLate ?? 0,
            'grace_period_minutes' => $grace,
            'chargeable_late_minutes' => $chargeableLate,
            'undertime_minutes' => $undertime,
            'worked_minutes' => $worked,
            'status' => $status,
            'source' => 'approved_correction',
        ]);
    }

    private function timestamp($value): ?string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i:s') : null;
    }

    private function validateIntervals($timeIn, $timeOut, $breakOut, $breakIn): void
    {
        $in = $timeIn ? Carbon::parse($timeIn) : null;
        $out = $timeOut ? Carbon::parse($timeOut) : null;
        $bOut = $breakOut ? Carbon::parse($breakOut) : null;
        $bIn = $breakIn ? Carbon::parse($breakIn) : null;

        if ($in && $out && ($out->lessThanOrEqualTo($in) || $in->diffInSeconds($out) > 86400)) {
            throw ValidationException::withMessages([
                'requested_time' => ['Time Out must be later than Time In, within 24 hours.'],
            ]);
        }

        if (($bOut && !$bIn) || (!$bOut && $bIn)) {
            throw ValidationException::withMessages([
                'requested_time' => ['The existing break record is incomplete; ask HR to review it first.'],
            ]);
        }

        if ($bOut && $bIn && (
            $bIn->lessThanOrEqualTo($bOut)
            || ($in && $bOut->lessThan($in))
            || ($out && $bIn->greaterThan($out))
        )) {
            throw ValidationException::withMessages([
                'requested_time' => ['The corrected time conflicts with the existing break interval.'],
            ]);
        }
    }

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

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LateAdjustment;
use App\Models\TimeLog;
use App\Models\TimeLogCorrectionRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LateAdjustmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdminPrivilege($request, 'view');

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([
                'all', 'pending', 'approved', 'rejected',
            ])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LateAdjustment::query()
            ->with(['employee', 'timeLog', 'requester', 'reviewer']);

        if (!empty($data['search'])) {
            $search = $data['search'];

            $query->whereHas('employee', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('firstname', 'like', "%{$search}%")
                        ->orWhere('lastname', 'like', "%{$search}%")
                        ->orWhere('emp_id', 'like', "%{$search}%")
                        ->orWhereRaw(
                            "CONCAT_WS(' ', firstname, lastname) LIKE ?",
                            ["%{$search}%"]
                        );
                });
            });
        }

        if (!empty($data['department_id'])) {
            $query->whereHas('employee', fn ($q) =>
                $q->where('department_id', $data['department_id'])
            );
        }

        if (!empty($data['date_from'])) {
            $query->whereHas('timeLog', fn ($q) =>
                $q->whereDate('work_date', '>=', $data['date_from'])
            );
        }

        if (!empty($data['date_to'])) {
            $query->whereHas('timeLog', fn ($q) =>
                $q->whereDate('work_date', '<=', $data['date_to'])
            );
        }

        if (($data['status'] ?? 'all') !== 'all') {
            $query->where('status', $data['status']);
        }

        $paginator = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 8));

        $departments = DB::table('departments')
            ->pluck('department_name', 'id')
            ->all();

        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()
                ->map(fn ($item) => $this->formatRecord($item, $departments))
                ->values(),
            'stats' => [
                'late_records' => LateAdjustment::count(),
                'pending_adjustments' => LateAdjustment::where(
                    'status', 'pending'
                )->count(),
                'approved_adjustments' => LateAdjustment::where(
                    'status', 'approved'
                )->count(),
                'minutes_adjusted' => (int) LateAdjustment::query()
                ->where('status', 'approved')
                ->selectRaw(
                    'SUM(COALESCE(approved_minutes, approved_waiver_minutes, 0)) as total'
                )
                ->value('total'),
                'rejected_adjustments' => LateAdjustment::where(
                    'status', 'rejected'
                )->count(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
        ]);
    }

    /**
     * Attendance records available for a new request.
     * Existing pending/approved adjustments are excluded.
     */
    public function eligibleLogs(Request $request): JsonResponse
    {
        $this->authorizeAdminPrivilege($request, 'view');

        $logs = TimeLog::query()
            ->with('employee')
            ->whereNotNull('time_in')
            ->whereNotNull('time_out')
            ->whereNotNull('scheduled_time_in')
            ->whereNotNull('scheduled_time_out')
            ->where(function ($q) {
                $q->where('chargeable_late_minutes', '>', 0)
                    ->orWhere('undertime_minutes', '>', 0);
            })
            ->whereDoesntHave('lateAdjustments', function ($q) {
                $q->whereIn('status', ['pending', 'approved']);
            })
            ->whereDoesntHave(
                'correctionRequests',
                fn ($q) => $q->where('status', 'pending')
            )
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $logs->map(function (TimeLog $log) {
                return [
                    'time_log_id' => $log->id,
                    'employee_id' => $log->employee_id,
                    'employee_name' => trim(
                        ($log->employee?->firstname ?? '') . ' ' .
                        ($log->employee?->lastname ?? '')
                    ),
                    'employee_number' => $log->employee?->emp_id,
                    'department' => $log->employee?->department?->department_name
                        ?? '',
                    'work_date' => $log->work_date?->format('Y-m-d'),
                    'schedule' => $this->time($log->scheduled_time_in) .
                        ' - ' . $this->time($log->scheduled_time_out),
                    'time_in' => $this->time24($log->time_in),
                    'time_out' => $this->time24($log->time_out),
                    'raw_late_minutes' => (int) (
                        $log->raw_late_minutes ?? $log->late_minutes ?? 0
                    ),
                    'grace_period_minutes' => (int) (
                        $log->grace_period_minutes ?? 0
                    ),
                    'chargeable_late_minutes' => (int) (
                        $log->chargeable_late_minutes ?? 0
                    ),
                    'undertime_minutes' => (int) (
                        $log->undertime_minutes ?? 0
                    ),
                ];
            })->values(),
        ]);
    }

    public function show(
        Request $request,
        LateAdjustment $lateAdjustment
    ): JsonResponse {
        $this->authorizeAdminPrivilege($request, 'view');

        $lateAdjustment->load([
            'timeLog', 'employee', 'requester', 'reviewer',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatRecord(
                $lateAdjustment,
                DB::table('departments')
                    ->pluck('department_name', 'id')->all()
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdminPrivilege($request, 'adjustment.request');

        $data = $this->validatePayload($request);

        $item = DB::transaction(function () use ($data, $request) {
            $log = TimeLog::query()
                ->whereKey($data['time_log_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureEligible($log);

            $values = $this->buildValues($log, $data);

            return LateAdjustment::create([
                ...$values,
                'time_log_id' => $log->id,
                'employee_id' => $log->employee_id,
                'requested_by' => $request->user()->id,
                'status' => 'pending',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Adjustment request submitted successfully.',
            'data' => $item,
        ], 201);
    }

    public function update(
        Request $request,
        LateAdjustment $lateAdjustment
    ): JsonResponse {
        $this->authorizeAdminPrivilege($request, 'adjustment.request');

        $data = $this->validatePayload($request, false);

        $item = DB::transaction(function () use (
            $lateAdjustment, $data
        ) {
            $log = TimeLog::query()
                ->whereKey($lateAdjustment->time_log_id)
                ->lockForUpdate()
                ->firstOrFail();

            $item = LateAdjustment::query()
                ->whereKey($lateAdjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $item->status === 'pending',
                409,
                'Only pending requests can be edited.'
            );

            $this->ensureEligible($log, $item->id);

            $item->update($this->buildValues($log, $data));

            return $item->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Pending adjustment updated successfully.',
            'data' => $item,
        ]);
    }

    public function approve(
        Request $request,
        LateAdjustment $lateAdjustment
    ): JsonResponse {
        $this->authorizeAdminPrivilege($request, 'adjustment.review');

        $data = $request->validate([
            'review_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $item = DB::transaction(function () use (
            $request, $lateAdjustment, $data
        ) {
            $log = TimeLog::query()
                ->whereKey($lateAdjustment->time_log_id)
                ->lockForUpdate()
                ->firstOrFail();

            $item = LateAdjustment::query()
                ->whereKey($lateAdjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $item->status === 'pending',
                409,
                'This adjustment was already reviewed.'
            );

            $this->ensureEligible($log, $item->id);

            // Prevent approval if punches changed since submission.
            if (
                $this->timestamp($log->time_in) !==
                    $this->timestamp($item->original_time_in) ||
                $this->timestamp($log->time_out) !==
                    $this->timestamp($item->original_time_out)
            ) {
                throw new HttpException(
                    409,
                    'Attendance changed since the request was created. ' .
                    'Edit or recreate the request using current attendance.'
                );
            }

            $type = $item->adjustment_type ?: 'late';

            // Recalculate using the current schedule.
            $values = $this->calculate(
                $log,
                $type,
                $this->time24(
                    $item->requested_time_in ?? $log->time_in
                ),
                $this->time24(
                    $item->requested_time_out ?? $log->time_out
                )
            );

            $waiver = (int) (
                $item->requested_minutes
                ?? $item->requested_waiver_minutes
                ?? 0
            );

            $maximum = $type === 'late'
                ? $values['chargeable']
                : $values['undertime'];

            if ($waiver > $maximum) {
                throw ValidationException::withMessages([
                    'requested_minutes' =>
                        'Requested waiver exceeds the recalculated minutes.',
                ]);
            }

            // Only approval updates actual attendance punches.
            $changedPunches =
                $this->timestamp($log->time_in) !==
                    $this->timestamp($values['time_in']) ||
                $this->timestamp($log->time_out) !==
                    $this->timestamp($values['time_out']);

            if ($changedPunches) {
                $log->update([
                    'time_in' => $values['time_in'],
                    'time_out' => $values['time_out'],
                    'raw_late_minutes' => $values['raw_late'],
                    'late_minutes' => $values['raw_late'],
                    'grace_period_minutes' => $values['grace'],
                    'chargeable_late_minutes' => $values['chargeable'],
                    'undertime_minutes' => $values['undertime'],
                    'worked_minutes' => $values['worked'],
                    'status' => $values['chargeable'] > 0
                        ? 'Late'
                        : ($values['undertime'] > 0
                            ? 'Undertime'
                            : 'Complete'),
                ]);
            }

            $item->update([
                'status' => 'approved',
                'approved_minutes' => $waiver,
                'approved_waiver_minutes' => $type === 'late'
                    ? $waiver
                    : 0,
                'final_late_minutes' => max(
                    0,
                    $values['chargeable'] -
                        ($type === 'late' ? $waiver : 0)
                ),
                'final_undertime_minutes' => max(
                    0,
                    $values['undertime'] -
                        ($type === 'undertime' ? $waiver : 0)
                ),
                'reviewed_by' => $request->user()->id,
                'review_remarks' => $data['review_remarks'] ?? null,
                'reviewed_at' => now(),
            ]);

            return $item->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Adjustment approved successfully.',
            'data' => $item,
        ]);
    }

    public function reject(
        Request $request,
        LateAdjustment $lateAdjustment
    ): JsonResponse {
        $this->authorizeAdminPrivilege($request, 'adjustment.review');

        $data = $request->validate([
            'review_remarks' => [
                'required', 'string', 'min:5', 'max:2000',
            ],
        ]);

        $item = DB::transaction(function () use (
            $request, $lateAdjustment, $data
        ) {
            $item = LateAdjustment::query()
                ->whereKey($lateAdjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $item->status === 'pending',
                409,
                'Only pending requests can be rejected.'
            );

            $item->update([
                'status' => 'rejected',
                'approved_minutes' => null,
                'approved_waiver_minutes' => null,
                'final_late_minutes' => null,
                'final_undertime_minutes' => null,
                'reviewed_by' => $request->user()->id,
                'review_remarks' => $data['review_remarks'],
                'reviewed_at' => now(),
            ]);

            return $item->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Adjustment request rejected.',
            'data' => $item,
        ]);
    }

    private function validatePayload(
        Request $request,
        bool $creating = true
    ): array {
        return $request->validate([
            'time_log_id' => [
                $creating ? 'required' : 'sometimes',
                'integer',
                'exists:time_logs,id',
            ],
            'adjustment_type' => [
                'required', Rule::in(['late', 'undertime']),
            ],
            'requested_time_in' => [
                'required', 'date_format:H:i',
            ],
            'requested_time_out' => [
                'required', 'date_format:H:i',
            ],
            'requested_minutes' => [
                'required', 'integer', 'min:0',
            ],
            'reason' => [
                'required', 'string', 'min:5', 'max:2000',
            ],
        ]);
    }

    private function ensureEligible(
        TimeLog $log,
        ?int $ignoreAdjustmentId = null
    ): void {
        if (
            !$log->time_in ||
            !$log->time_out ||
            !$log->scheduled_time_in ||
            !$log->scheduled_time_out
        ) {
            throw ValidationException::withMessages([
                'time_log_id' =>
                    'A complete attendance record and schedule are required.',
            ]);
        }

        if (
            (int) $log->chargeable_late_minutes <= 0 &&
            (int) $log->undertime_minutes <= 0
        ) {
            throw ValidationException::withMessages([
                'time_log_id' =>
                    'The attendance has no late or undertime minutes.',
            ]);
        }

        $hasCorrection = TimeLogCorrectionRequest::query()
            ->where('time_log_id', $log->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasCorrection) {
            throw ValidationException::withMessages([
                'time_log_id' =>
                    'Review the pending Time Log Correction first.',
            ]);
        }

        $hasActive = LateAdjustment::query()
            ->where('time_log_id', $log->id)
            ->whereIn('status', ['pending', 'approved'])
            ->when(
                $ignoreAdjustmentId !== null,
                fn ($q) => $q->where('id', '!=', $ignoreAdjustmentId)
            )
            ->exists();

        if ($hasActive) {
            throw ValidationException::withMessages([
                'time_log_id' =>
                    'This attendance already has a pending or approved adjustment.',
            ]);
        }
    }

    private function buildValues(TimeLog $log, array $data): array
    {
        $type = $data['adjustment_type'];

        $calculated = $this->calculate(
            $log,
            $type,
            $data['requested_time_in'],
            $data['requested_time_out']
        );

        $waiver = (int) $data['requested_minutes'];

        $originalMinutes = $type === 'late'
            ? (int) $log->chargeable_late_minutes
            : (int) $log->undertime_minutes;

        $newMinutes = $type === 'late'
            ? $calculated['chargeable']
            : $calculated['undertime'];

        $changedPunches =
            $this->timestamp($log->time_in) !==
                $this->timestamp($calculated['time_in']) ||
            $this->timestamp($log->time_out) !==
                $this->timestamp($calculated['time_out']);

        if (!$changedPunches && $waiver === 0) {
            throw ValidationException::withMessages([
                'requested_minutes' =>
                    'Change the requested time or enter waiver minutes.',
            ]);
        }

        if ($waiver > $newMinutes) {
            throw ValidationException::withMessages([
                'requested_minutes' =>
                    "Waiver cannot exceed {$newMinutes} minutes.",
            ]);
        }

        return [
            'adjustment_type' => $type,
            'original_time_in' => $log->time_in,
            'original_time_out' => $log->time_out,
            'requested_time_in' => $calculated['time_in'],
            'requested_time_out' => $calculated['time_out'],
            'original_minutes' => $originalMinutes,
            'requested_minutes' => $waiver,
            'raw_late_minutes' => (int) (
                $log->raw_late_minutes ?? $log->late_minutes ?? 0
            ),
            'grace_period_minutes' => (int) (
                $log->grace_period_minutes ?? 0
            ),
            'chargeable_late_minutes' =>
                (int) $log->chargeable_late_minutes,
            'requested_waiver_minutes' => $type === 'late'
                ? $waiver
                : 0,
            'reason' => $data['reason'],
        ];
    }

    /**
     * Same-day schedule calculations.
     * Overnight shifts require a separate scheduling policy.
     */
    private function calculate(
        TimeLog $log,
        string $type,
        string $requestedIn,
        string $requestedOut
    ): array {
        $day = Carbon::parse($log->work_date)->toDateString();

        $in = Carbon::createFromFormat(
            'Y-m-d H:i', "{$day} {$requestedIn}"
        )->startOfMinute();

        $out = Carbon::createFromFormat(
            'Y-m-d H:i', "{$day} {$requestedOut}"
        )->startOfMinute();

        $scheduledIn = Carbon::parse($log->scheduled_time_in);
        $scheduledOut = Carbon::parse($log->scheduled_time_out);

        if (
            $out->lessThanOrEqualTo($in) ||
            $scheduledOut->lessThanOrEqualTo($scheduledIn) ||
            $scheduledIn->toDateString() !== $day
        ) {
            throw ValidationException::withMessages([
                'requested_time_out' =>
                    'This form currently supports same-day work schedules only.',
            ]);
        }

        $rawLate = max(
            0,
            (int) $scheduledIn->diffInMinutes($in, false)
        );

        $grace = (int) ($log->grace_period_minutes ?? 0);
        $chargeable = max(0, $rawLate - $grace);

        $undertime = max(
            0,
            (int) $out->diffInMinutes($scheduledOut, false)
        );

        // Retain recorded breaks without exposing them in the form.
        $breakMinutes = 0;

        if ($log->break_out && $log->break_in) {
            $breakOut = Carbon::parse($log->break_out);
            $breakIn = Carbon::parse($log->break_in);

            if (
                $breakIn->greaterThan($breakOut) &&
                $breakOut->greaterThanOrEqualTo($in) &&
                $breakIn->lessThanOrEqualTo($out)
            ) {
                $breakMinutes = (int) $breakOut->diffInMinutes($breakIn);
            }
        }

        $worked = max(
            0,
            (int) $in->diffInMinutes($out) - $breakMinutes
        );

        return [
            'time_in' => $in,
            'time_out' => $out,
            'raw_late' => $rawLate,
            'grace' => $grace,
            'chargeable' => $chargeable,
            'undertime' => $undertime,
            'worked' => $worked,
        ];
    }

    private function formatRecord(
        LateAdjustment $item,
        array $departments
    ): array {
        $employee = $item->employee;
        $log = $item->timeLog;

        $type = $item->adjustment_type ?: 'late';

        $original = $item->original_minutes ?? (
            $type === 'late'
                ? $item->chargeable_late_minutes
                : ($log?->undertime_minutes ?? 0)
        );

        $requested = $item->requested_minutes
            ?? $item->requested_waiver_minutes
            ?? 0;

        $approved = $item->approved_minutes
            ?? $item->approved_waiver_minutes
            ?? 0;

        return [
            'id' => $item->id,
            'adjustment_id' => $item->id,
            'time_log_id' => $item->time_log_id,
            'employee_id' => $item->employee_id,
            'employee_number' => $employee?->emp_id ?? '',
            'employee_name' => trim(
                ($employee?->firstname ?? '') . ' ' .
                ($employee?->lastname ?? '')
            ),
            'department_id' => $employee?->department_id,
            'department' => $departments[$employee?->department_id]
                ?? 'Unassigned',
            'work_date' => $log?->work_date?->format('Y-m-d'),
            'schedule' => $log
                ? $this->time($log->scheduled_time_in) . ' - ' .
                    $this->time($log->scheduled_time_out)
                : '',
            'adjustment_type' => $type,
            'original_time_in' => $this->time24(
                $item->original_time_in ?? $log?->time_in
            ),
            'original_time_out' => $this->time24(
                $item->original_time_out ?? $log?->time_out
            ),
            'requested_time_in' => $this->time24(
                $item->requested_time_in ?? $log?->time_in
            ),
            'requested_time_out' => $this->time24(
                $item->requested_time_out ?? $log?->time_out
            ),
            'original_minutes' => (int) $original,
            'requested_minutes' => (int) $requested,
            'approved_minutes' => (int) $approved,
            'raw_late_minutes' => (int) $item->raw_late_minutes,
            'grace_period_minutes' => (int) $item->grace_period_minutes,
            'chargeable_late_minutes' =>
                (int) $item->chargeable_late_minutes,
            'approved_waiver_minutes' =>
                (int) $item->approved_waiver_minutes,
            'final_late_minutes' =>
                $item->final_late_minutes,
            'final_undertime_minutes' =>
                $item->final_undertime_minutes,
            'status' => $item->status,
            'reason' => $item->reason,
            'review_remarks' => $item->review_remarks,
            'requested_by' => $item->requested_by,
            'requested_by_name' => $item->requester?->name,
            'reviewed_by_name' => $item->reviewer?->name,
            'requested_at' =>
                $item->created_at?->toDateTimeString(),
            'reviewed_at' =>
                $item->reviewed_at?->toDateTimeString(),
        ];
    }

    private function time($value): ?string
    {
        return $value
            ? Carbon::parse($value)->format('h:i A')
            : null;
    }

    private function time24($value): ?string
    {
        return $value
            ? Carbon::parse($value)->format('H:i')
            : null;
    }

    private function timestamp($value): ?string
    {
        return $value
            ? Carbon::parse($value)->format('Y-m-d H:i:s')
            : null;
    }

    private function authorizeAdminPrivilege(
        Request $request,
        string $name
    ): void {
        $user = $request->user();

        abort_unless(
            $user
            && in_array(
                $user->role?->name,
                ['Administrator', 'Human Resources'],
                true
            )
            && $user->hasPrivilege('timekeeping', $name),
            403,
            'You are not authorized to perform this action.'
        );
    }
}

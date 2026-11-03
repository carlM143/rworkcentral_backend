<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LateAdjustment;
use App\Models\TimeLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\TimeLogCorrectionRequest;

class LateAdjustmentController extends Controller
{
    /**
     * List actual late attendance records.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdminPrivilege($request, 'view');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'status' => [
                'nullable',
                'in:all,unadjusted,pending,approved,rejected',
            ],
            'date_from' => ['nullable', 'date'],
            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $query = TimeLog::query()
            ->with([
                'employee',
                'latestLateAdjustment',
            ])
            ->whereNotNull('chargeable_late_minutes')
            ->where('chargeable_late_minutes', '>', 0);

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->whereHas(
                'employee',
                function ($q) use ($search) {
                    $q->where(function ($employeeQuery) use ($search) {
                        $employeeQuery
                            ->where(
                                'firstname',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'lastname',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'emp_id',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhereRaw(
                                "CONCAT_WS(' ', firstname, lastname) LIKE ?",
                                ["%{$search}%"]
                            );
                    });
                }
            );
        }

        if (!empty($validated['department_id'])) {
            $departmentId = $validated['department_id'];

            $query->whereHas(
                'employee',
                fn ($q) => $q->where(
                    'department_id',
                    $departmentId
                )
            );
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate(
                'work_date',
                '>=',
                $validated['date_from']
            );
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate(
                'work_date',
                '<=',
                $validated['date_to']
            );
        }

        $status = $validated['status'] ?? 'all';

        if ($status === 'unadjusted') {
            $query->whereDoesntHave('latestLateAdjustment');
        } elseif ($status !== 'all') {
            $query->whereHas(
                'latestLateAdjustment',
                fn ($q) => $q->where('status', $status)
            );
        }

        $logs = $query
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 10));

        $departments = DB::table('departments')
            ->pluck('department_name', 'id')
            ->all();

        $records = $logs->getCollection()
            ->map(
                fn (TimeLog $log) => $this->formatRecord(
                    $log,
                    $departments
                )
            )
            ->values();

        $base = TimeLog::query()
            ->whereNotNull('chargeable_late_minutes')
            ->where('chargeable_late_minutes', '>', 0);

        $stats = [
            'late_records' => (clone $base)->count(),

            'pending_adjustments' =>
                LateAdjustment::where('status', 'pending')
                    ->count(),

            'approved_adjustments' =>
                LateAdjustment::where('status', 'approved')
                    ->count(),

            'minutes_adjusted' => (int) LateAdjustment::query()
                ->where('status', 'approved')
                ->sum('approved_waiver_minutes'),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Late adjustment records retrieved.',
            'data' => $records,
            'stats' => $stats,
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem() ?? 0,
                'to' => $logs->lastItem() ?? 0,
            ],
        ]);
    }

    /**
     * View an adjustment and its original attendance.
     */
    public function show(Request $request, LateAdjustment $lateAdjustment): JsonResponse
    {
        $this->authorizeAdminPrivilege($request, 'view');

        $lateAdjustment->load([
            'employee',
            'timeLog',
            'requester',
            'reviewer',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $lateAdjustment->id,
                'time_log_id' => $lateAdjustment->time_log_id,
                'employee_id' => $lateAdjustment->employee_id,

                'employee_name' => trim(
                    ($lateAdjustment->employee?->firstname ?? '') .
                    ' ' .
                    ($lateAdjustment->employee?->lastname ?? '')
                ),

                'work_date' => $lateAdjustment->timeLog?->work_date
                    ? Carbon::parse(
                        $lateAdjustment->timeLog->work_date
                    )->toDateString()
                    : null,

                'actual_time_in' =>
                    $this->formatTime(
                        $lateAdjustment->timeLog?->time_in
                    ),

                'scheduled_time_in' =>
                    $this->formatTime(
                        $lateAdjustment->timeLog?->scheduled_time_in
                    ),

                'raw_late_minutes' =>
                    $lateAdjustment->raw_late_minutes,

                'grace_period_minutes' =>
                    $lateAdjustment->grace_period_minutes,

                'chargeable_late_minutes' =>
                    $lateAdjustment->chargeable_late_minutes,

                'requested_waiver_minutes' =>
                    $lateAdjustment->requested_waiver_minutes,

                'approved_waiver_minutes' =>
                    $lateAdjustment->approved_waiver_minutes,

                'final_late_minutes' =>
                    $lateAdjustment->final_late_minutes,

                'status' => $lateAdjustment->status,
                'reason' => $lateAdjustment->reason,
                'review_remarks' => $lateAdjustment->review_remarks,

                'requested_by' => $lateAdjustment->requested_by,
                'reviewed_by' => $lateAdjustment->reviewed_by,

                'reviewed_at' =>
                    $lateAdjustment->reviewed_at?->toDateTimeString(),

                'created_at' =>
                    $lateAdjustment->created_at?->toDateTimeString(),
            ],
        ]);
    }

    /**
     * Submit an additional late-minute waiver request.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdminPrivilege($request, 'adjustment.request');

        $validated = $request->validate([
            'time_log_id' => [
                'required',
                'integer',
                'exists:time_logs,id',
            ],
            'requested_waiver_minutes' => [
                'required',
                'integer',
                'min:1',
            ],
            'reason' => [
                'required',
                'string',
                'min:5',
                'max:2000',
            ],
        ]);

        $adjustment = DB::transaction(function () use (
            $request,
            $validated
        ) {
            $log = TimeLog::query()
                ->whereKey($validated['time_log_id'])
                ->lockForUpdate()
                ->firstOrFail();

         $hasPendingCorrection = TimeLogCorrectionRequest::query()
                ->where('time_log_id', $log->id)
                ->where('status', 'pending')
                ->exists();

            if ($hasPendingCorrection) {
                throw ValidationException::withMessages([
                    'time_log_id' =>
                        'This attendance has a pending Time Log Correction. ' .
                        'The correction must be reviewed before requesting a late adjustment.',
                ]);
            }       

            if (
                $log->chargeable_late_minutes === null ||
                (int) $log->chargeable_late_minutes <= 0 ||
                !$log->scheduled_time_in
            ) {
                throw ValidationException::withMessages([
                    'time_log_id' =>
                        'This attendance record has no verified chargeable late minutes.',
                ]);
            }

            $hasActiveAdjustment = LateAdjustment::query()
                ->where('time_log_id', $log->id)
                ->whereIn('status', [
                    'pending',
                    'approved',
                ])
                ->exists();

            if ($hasActiveAdjustment) {
                throw ValidationException::withMessages([
                    'time_log_id' =>
                        'This attendance already has a pending or approved adjustment.',
                ]);
            }

            $chargeableLate =
                (int) $log->chargeable_late_minutes;

            $requestedWaiver =
                (int) $validated['requested_waiver_minutes'];

            if ($requestedWaiver > $chargeableLate) {
                throw ValidationException::withMessages([
                    'requested_waiver_minutes' =>
                        "Waiver cannot exceed {$chargeableLate} chargeable late minutes.",
                ]);
            }

            return LateAdjustment::create([
                'time_log_id' => $log->id,
                'employee_id' => $log->employee_id,
                'requested_by' => $request->user()->id,

                'raw_late_minutes' =>
                    (int) ($log->raw_late_minutes
                        ?? $log->late_minutes
                        ?? 0),

                'grace_period_minutes' =>
                    (int) ($log->grace_period_minutes ?? 0),

                'chargeable_late_minutes' => $chargeableLate,

                'requested_waiver_minutes' => $requestedWaiver,

                'status' => 'pending',
                'reason' => $validated['reason'],
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Late adjustment request submitted.',
            'data' => $adjustment,
        ], 201);
    }

    /**
     * Approve an HR waiver.
     */
    public function approve(
        Request $request,
        LateAdjustment $lateAdjustment
    ): JsonResponse {
        $this->authorizeAdminPrivilege($request, 'adjustment.review');

        $validated = $request->validate([
            'review_remarks' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $adjustment = DB::transaction(function () use (
            $request,
            $lateAdjustment,
            $validated
        ) {
            // Always lock TimeLog first, matching store().
            $log = TimeLog::query()
                ->whereKey($lateAdjustment->time_log_id)
                ->lockForUpdate()
                ->firstOrFail();

            $item = LateAdjustment::query()
                ->whereKey($lateAdjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

        $hasPendingCorrection = TimeLogCorrectionRequest::query()
                ->where('time_log_id', $log->id)
                ->where('status', 'pending')
                ->exists();

            if ($hasPendingCorrection) {
                throw new HttpException(
                    409,
                    'This attendance has a pending Time Log Correction. ' .
                    'Review the correction before approving the late adjustment.'
                );
            }        

            if ($item->status !== 'pending') {
                throw new HttpException(
                    409,
                    'This adjustment has already been reviewed.'
                );
            }

            if (
                (int) $log->employee_id !==
                (int) $item->employee_id
            ) {
                throw new HttpException(
                    409,
                    'Employee reference no longer matches the attendance.'
                );
            }

            // Prevent approval of a stale attendance snapshot.
            $currentRaw = (int) (
                $log->raw_late_minutes
                ?? $log->late_minutes
                ?? 0
            );

            $currentGrace = (int) (
                $log->grace_period_minutes ?? 0
            );

            $currentChargeable = $log->chargeable_late_minutes;

            if (
                $currentChargeable === null ||
                $currentRaw !== (int) $item->raw_late_minutes ||
                $currentGrace !== (int) $item->grace_period_minutes ||
                (int) $currentChargeable !==
                    (int) $item->chargeable_late_minutes
            ) {
                throw new HttpException(
                    409,
                    'Attendance computation has changed. Review the latest time log before approving.'
                );
            }

            $existingApproved = LateAdjustment::query()
                ->where('time_log_id', $log->id)
                ->where('status', 'approved')
                ->where('id', '!=', $item->id)
                ->exists();

            if ($existingApproved) {
                throw new HttpException(
                    409,
                    'This attendance already has an approved adjustment.'
                );
            }

            $waiver = (int) $item->requested_waiver_minutes;
            $chargeable = (int) $currentChargeable;

            if ($waiver <= 0 || $waiver > $chargeable) {
                throw new HttpException(
                    409,
                    'Requested waiver is no longer valid.'
                );
            }

            $item->update([
                'status' => 'approved',

                'approved_waiver_minutes' => $waiver,

                'final_late_minutes' => max(
                    0,
                    $chargeable - $waiver
                ),

                'reviewed_by' => $request->user()->id,
                'review_remarks' =>
                    $validated['review_remarks'] ?? null,

                'reviewed_at' => now(),
            ]);

            return $item->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Late adjustment approved successfully.',
            'data' => $adjustment,
        ]);
    }

    /**
     * Reject an HR waiver.
     */
    public function reject(
        Request $request,
        LateAdjustment $lateAdjustment
    ): JsonResponse {
        $this->authorizeAdminPrivilege($request, 'adjustment.review');

        $validated = $request->validate([
            'review_remarks' => [
                'required',
                'string',
                'min:5',
                'max:2000',
            ],
        ]);

        $adjustment = DB::transaction(function () use (
            $request,
            $lateAdjustment,
            $validated
        ) {
            $log = TimeLog::query()
                ->whereKey($lateAdjustment->time_log_id)
                ->lockForUpdate()
                ->firstOrFail();

            $item = LateAdjustment::query()
                ->whereKey($lateAdjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($item->status !== 'pending') {
                throw new HttpException(
                    409,
                    'This adjustment has already been reviewed.'
                );
            }

            $item->update([
                'status' => 'rejected',

                'approved_waiver_minutes' => null,
                'final_late_minutes' => null,

                'reviewed_by' => $request->user()->id,
                'review_remarks' => $validated['review_remarks'],
                'reviewed_at' => now(),
            ]);

            return $item->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Late adjustment rejected.',
            'data' => $adjustment,
        ]);
    }

    private function formatRecord(
        TimeLog $log,
        array $departments
    ): array {
        $employee = $log->employee;
        $latest = $log->latestLateAdjustment;

        $chargeable = (int) (
            $log->chargeable_late_minutes ?? 0
        );

        $approvedWaiver = $latest?->status === 'approved'
            ? (int) ($latest->approved_waiver_minutes ?? 0)
            : 0;

        $finalLate = max(
            0,
            $chargeable - $approvedWaiver
        );

        $name = trim(
            ($employee?->firstname ?? '') . ' ' .
            ($employee?->lastname ?? '')
        );

        $departmentId = $employee?->department_id;

        return [
            'id' => $log->id,
            'time_log_id' => $log->id,
            'employee_id' => $log->employee_id,

            'employee_number' => $employee?->emp_id ?? '',
            'employee_name' => $name ?: 'Unknown Employee',

            'department_id' => $departmentId,
            'department' =>
                $departments[$departmentId] ?? 'Unassigned',

            'work_date' => Carbon::parse(
                $log->work_date
            )->toDateString(),

            'schedule' =>
                $this->formatTime($log->scheduled_time_in) .
                ' - ' .
                $this->formatTime($log->scheduled_time_out),

            'actual_time_in' =>
                $this->formatTime($log->time_in),

            'raw_late_minutes' => (int) (
                $log->raw_late_minutes
                ?? $log->late_minutes
                ?? 0
            ),

            'grace_period_minutes' => (int) (
                $log->grace_period_minutes ?? 0
            ),

            'chargeable_late_minutes' => $chargeable,

            'requested_waiver_minutes' =>
                $latest?->requested_waiver_minutes ?? 0,

            'approved_waiver_minutes' => $approvedWaiver,
            'final_late_minutes' => $finalLate,

            'status' => $latest?->status ?? 'unadjusted',

            'adjustment_id' => $latest?->id,
            'reason' => $latest?->reason,

            'review_remarks' => $latest?->review_remarks,

            'requested_at' =>
                $latest?->created_at?->toDateTimeString(),

            'reviewed_at' =>
                $latest?->reviewed_at?->toDateTimeString(),
        ];
    }

    private function formatTime($value): ?string
    {
        return $value
            ? Carbon::parse($value)->format('h:i A')
            : null;
    }
    /** Admin-scoped endpoints. Team/employee APIs must use scoped routes. */
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
}

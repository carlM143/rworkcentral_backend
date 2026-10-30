<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TimeLog;
use App\Models\TimeLogCorrectionRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TimeLogCorrectionRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([
                'pending', 'approved', 'rejected',
            ])],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $query = TimeLogCorrectionRequest::query()
            ->with(['employee', 'requester', 'reviewer', 'timeLog'])
            ->latest();

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (!empty($data['employee_id'])) {
            $query->where('employee_id', $data['employee_id']);
        }

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
            'data' => $query->paginate(15),
            'stats' => $stats,
        ]);
    }

    public function history(TimeLog $timeLog): JsonResponse
    {
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

            $timeIn = $item->requested_time_in;
            $timeOut = $item->requested_time_out;
            $breakOut = $item->requested_break_out;
            $breakIn = $item->requested_break_in;

            $this->validateIntervals(
                $timeIn, $timeOut, $breakOut, $breakIn
            );

            $late = 0;
            $undertime = 0;
            $worked = 0;

            $date = Carbon::parse($log->work_date)->format('Y-m-d');
            $scheduledIn = Carbon::parse("{$date} 08:00:00");
            $scheduledOut = Carbon::parse("{$date} 17:00:00");

            if ($timeIn && $timeIn->gt($scheduledIn)) {
                $late = (int) $scheduledIn
                    ->diffInMinutes($timeIn, true);
            }

            if ($timeOut && $timeOut->lt($scheduledOut)) {
                $undertime = (int) $timeOut
                    ->diffInMinutes($scheduledOut, true);
            }

            if ($timeIn && $timeOut) {
                $worked = (int) $timeIn
                    ->diffInMinutes($timeOut, true);

                if ($breakOut && $breakIn) {
                    $worked -= (int) $breakOut
                        ->diffInMinutes($breakIn, true);
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
                'late_minutes' => $late,
                'undertime_minutes' => $undertime,
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

    private function authorizeReviewer(Request $request): void
    {
        $user = $request->user();

        // Adapt the allowed codes/names to your existing roles table.
        $role = $user->role ?? null;

        $code = strtolower((string) ($role?->code ?? ''));
        $name = strtolower((string) ($role?->name ?? ''));

        $allowedCodes = [
            'system_administrator',
            'administrator',
            'admin',
            'hr_administrator',
            'hr_admin',
            'human_resources',
        ];

        $allowedNames = [
            'system administrator',
            'administrator',
            'hr administrator',
            'human resources administrator',
        ];

        abort_unless(
            in_array($code, $allowedCodes, true) ||
            in_array($name, $allowedNames, true),
            403,
            'You are not authorized to review corrections.'
        );
    }
}

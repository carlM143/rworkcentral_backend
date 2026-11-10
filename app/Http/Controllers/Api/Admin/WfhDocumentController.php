<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TimeLog;
use App\Models\WfhTracker;
use App\Models\WorkFromHome;
use App\Services\WfhScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class WfhDocumentController extends Controller
{
    public function __construct(private readonly WfhScheduleService $schedule)
    {
    }

    public function index(): JsonResponse
    {
        $records = WorkFromHome::query()
            ->whereIn('status', ['approved', 'cancelled'])
            ->with($this->relations())
            ->latest('start_date')
            ->latest('id')
            ->get()
            ->map(fn (WorkFromHome $wfh): array => $this->formatSummary($wfh))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'WFH documents retrieved successfully.',
            'data' => $records,
        ]);
    }

    public function show(WorkFromHome $workFromHome): JsonResponse
    {
        $workFromHome->load($this->relations());

        return response()->json([
            'success' => true,
            'message' => 'WFH document details retrieved successfully.',
            'data' => $this->formatDetail($workFromHome),
        ]);
    }

    /**
     * Attach proof of work for one WFH day (images and/or one document).
     * Creates the tracker row if it does not exist yet, then marks it submitted.
     */
    public function attach(Request $request, WorkFromHome $workFromHome): JsonResponse
    {
        $validated = $request->validate([
            'date_attendance' => ['required', 'date'],
            'images' => ['required_without:document', 'array', 'max:10'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:4096'],
        ]);

        if ($workFromHome->status !== 'approved') {
            throw ValidationException::withMessages([
                'status' => 'Documents can only be attached to approved WFH requests.',
            ]);
        }

        $workFromHome->load($this->relations());
        $date = Carbon::parse($validated['date_attendance'])->toDateString();

        if (! in_array($date, $this->schedule->workingDates($workFromHome), true)) {
            throw ValidationException::withMessages([
                'date_attendance' => 'The selected date is not a scheduled working day within this WFH request.',
            ]);
        }

        $storedPaths = [];
        $replacedDocument = null;
        $directory = "wfh/{$workFromHome->employee_id}/{$workFromHome->id}";

        try {
            DB::transaction(function () use (
                $request,
                $workFromHome,
                $date,
                $directory,
                &$storedPaths,
                &$replacedDocument
            ): void {
                $tracker = WfhTracker::query()
                    ->where('work_from_home_id', $workFromHome->id)
                    ->whereDate('date_attendance', $date)
                    ->lockForUpdate()
                    ->first();

                if ($tracker?->status === 'accepted') {
                    throw ValidationException::withMessages([
                        'date_attendance' => 'This WFH day was already accepted and can no longer be changed.',
                    ]);
                }

                $tracker ??= new WfhTracker([
                    'employee_id' => $workFromHome->employee_id,
                    'work_from_home_id' => $workFromHome->id,
                    'date_attendance' => $date,
                ]);

                if ($request->hasFile('document')) {
                    $replacedDocument = $tracker->doc_path;
                    $path = $request->file('document')->store($directory, 'public');
                    $storedPaths[] = $path;
                    $tracker->doc_path = $path;
                }

                // Resubmission resets the review; remarks are kept so HR
                // can still see why the day was returned before.
                $tracker->fill([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                    'reviewed_at' => null,
                    'approved_by' => null,
                ])->save();

                foreach ($request->file('images', []) as $image) {
                    $path = $image->store($directory, 'public');
                    $storedPaths[] = $path;
                    $tracker->images()->create(['image_path' => $path]);
                }
            });
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('public')->delete($storedPaths);
            }

            throw $exception;
        }

        if ($replacedDocument) {
            Storage::disk('public')->delete($replacedDocument);
        }

        return response()->json([
            'success' => true,
            'message' => 'Documents attached and submitted for review.',
            'data' => $this->freshDetail($workFromHome->id),
        ], 201);
    }

    public function accept(Request $request, WfhTracker $wfhTracker): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $tracker = $this->review($request, $wfhTracker, 'accepted', $validated['remarks'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'WFH day verified and recorded as attendance.',
            'data' => $this->freshDetail($tracker->work_from_home_id),
        ]);
    }

    public function returnDay(Request $request, WfhTracker $wfhTracker): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        $tracker = $this->review($request, $wfhTracker, 'returned', $validated['remarks']);

        return response()->json([
            'success' => true,
            'message' => 'WFH day returned to the employee.',
            'data' => $this->freshDetail($tracker->work_from_home_id),
        ]);
    }

    /**
     * Cancel an approved WFH request from Document Review without deleting records.
     */
    public function cancel(Request $request, WorkFromHome $workFromHome): JsonResponse
    {
        $requestNo = $workFromHome->request_no;

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $validated['reason'] ?? null;
        $actor = $request->user()?->name ?? 'Admin';
        $note = "Cancelled by {$actor}" . ($reason ? ": {$reason}" : '');

        DB::transaction(function () use ($workFromHome, $note) {
            $record = WorkFromHome::whereKey($workFromHome->id)->lockForUpdate()->firstOrFail();
            if (! in_array($record->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => 'Only pending or approved WFH requests can be cancelled.']);
            }
            $record->update([
                'status' => 'cancelled',
                'remarks' => $record->remarks ? $record->remarks . "\n" . $note : $note,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => "Work From Home request {$requestNo} was cancelled successfully.",
            'data' => $this->formatSummary($workFromHome->fresh($this->relations())),
        ]);
    }

    private function review(Request $request, WfhTracker $wfhTracker, string $status, ?string $remarks): WfhTracker
    {
        return DB::transaction(function () use ($request, $wfhTracker, $status, $remarks): WfhTracker {
            $parent = WorkFromHome::whereKey($wfhTracker->work_from_home_id)->lockForUpdate()->firstOrFail();
            if ($parent->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'Only approved WFH requests can be reviewed.']);
            }
            $record = WfhTracker::query()
                ->lockForUpdate()
                ->findOrFail($wfhTracker->id);

            if ($record->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'status' => 'Only submitted WFH days can be reviewed.',
                ]);
            }

            $record->update([
                'status' => $status,
                'approved_by' => $request->user()->id,
                'reviewed_at' => now(),
                'remarks' => $remarks ?? $record->remarks,
            ]);

            if ($status === 'accepted') {
                $this->recordAttendance($record, $remarks);
            }

            return $record;
        });
    }

    /**
     * Record a verified WFH day as a complete attendance log in time_logs.
     */
    private function recordAttendance(WfhTracker $tracker, ?string $remarks = null): void
    {
        $tracker->load([
            'workFromHome',
            'employee.latestEmployment.workSchedule.days',
            'employee.employments.workSchedule.days',
        ]);

        $workDate = $tracker->date_attendance->toDateString();
        $wfh = $tracker->workFromHome;
        if ($wfh && $tracker->employee) {
            $wfh->setRelation('employee', $tracker->employee);
        }

        $schedule = $wfh ? $this->schedule->getWorkSchedule($wfh) : null;
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

        $rawMinutes = max(0, (int) $scheduledIn->diffInMinutes($scheduledOut));
        $workedMinutes = $rawMinutes >= 480 ? $rawMinutes - 60 : $rawMinutes;
        $requestNo = $wfh?->request_no ?? 'WFH';

        $existingLog = TimeLog::query()
            ->where('employee_id', $tracker->employee_id)
            ->whereDate('work_date', $workDate)
            ->first();

        $actualIn = $existingLog?->time_in ?? $scheduledIn;
        $actualOut = $existingLog?->time_out ?? $scheduledOut;
        $actualWorkedMinutes = ($existingLog && $existingLog->worked_minutes > 0)
            ? (int) $existingLog->worked_minutes
            : $workedMinutes;

        if ($existingLog) {
            $existingLog->update([
                'work_schedule_id' => $schedule?->id ?? $existingLog->work_schedule_id,
                'scheduled_time_in' => $scheduledIn,
                'scheduled_time_out' => $scheduledOut,
                'time_in' => $actualIn,
                'time_out' => $actualOut,
                'worked_minutes' => $actualWorkedMinutes,
                'source' => 'wfh',
                'status' => 'complete',
                'remarks' => trim("Verified WFH Attendance ({$requestNo})" . ($remarks ? " — {$remarks}" : '')),
            ]);
            return;
        }

        TimeLog::create([
            'employee_id' => $tracker->employee_id,
            'work_date' => $workDate,
            'work_schedule_id' => $schedule?->id,
            'scheduled_time_in' => $scheduledIn,
            'scheduled_time_out' => $scheduledOut,
            'time_in' => $actualIn,
            'time_out' => $actualOut,
            'late_minutes' => 0,
            'raw_late_minutes' => 0,
            'grace_period_minutes' => (int) ($schedule?->grace_period ?? 0),
            'chargeable_late_minutes' => 0,
            'undertime_minutes' => 0,
            'worked_minutes' => $actualWorkedMinutes,
            'overtime_minutes' => 0,
            'source' => 'wfh',
            'status' => 'complete',
            'remarks' => trim("Verified WFH Attendance ({$requestNo})" . ($remarks ? " — {$remarks}" : '')),
        ]);
    }

    // =====================================================
    // FORMATTING
    // =====================================================

    /**
     * @return array<int|string, mixed>
     */
    private function relations(): array
    {
        return [
            'employee:id,emp_id,firstname,middlename,lastname,suffix,department_id',
            'employee.department:id,department_name',
            'employee.latestEmployment',
            'employee.latestEmployment.department:id,department_name',
            'employee.latestEmployment.position:id,position',
            'employee.latestEmployment.workSchedule:id,schedule_code,schedule_name,grace_period',
            'employee.latestEmployment.workSchedule.days:id,work_schedule_id,day_of_week,is_working_day,time_in,time_out',
            'approvedBy:id,name',
            'trackers' => fn ($query) => $query->orderBy('date_attendance'),
            'trackers.images',
            'trackers.approvedBy:id,name',
        ];
    }

    public function freshDetail(int $workFromHomeId): array
    {
        return $this->formatDetail(
            WorkFromHome::query()->with($this->relations())->findOrFail($workFromHomeId)
        );
    }

    public function formatDetail(WorkFromHome $wfh): array
    {
        return [
            ...$this->formatSummary($wfh),
            'days' => $this->formatDays($wfh),
        ];
    }

    public function formatSummary(WorkFromHome $wfh): array
    {
        $days = collect($this->formatDays($wfh));
        $count = fn (string $status): int => $days->where('status', $status)->count();

        $timeLogsCount = TimeLog::where('employee_id', $wfh->employee_id)
            ->where('source', 'wfh')
            ->where(fn ($q) => $q->where('remarks', 'like', "%{$wfh->request_no}%")
                ->orWhere(fn ($dq) => $dq->whereDate('work_date', '>=', $wfh->start_date)->whereDate('work_date', '<=', $wfh->end_date)))
            ->count();

        $counts = [
            'expected' => $days->count(),
            'not_submitted' => $count('not_submitted') + $count('draft'),
            'submitted' => $count('submitted'),
            'accepted' => $count('accepted'),
            'returned' => $count('returned'),
            'attachments' => $days->sum(fn (array $day): int => count($day['images']) + ($day['document'] ? 1 : 0)),
            'time_logs' => $timeLogsCount,
        ];

        $hasData = ($counts['attachments'] > 0) || ($counts['submitted'] > 0) || ($counts['accepted'] > 0) || ($timeLogsCount > 0);

        $documentStatus = match (true) {
            $wfh->status === 'cancelled' => 'cancelled',
            $counts['submitted'] > 0 => 'for_review',
            $counts['returned'] > 0 => 'returned',
            $counts['expected'] > 0 && $counts['accepted'] === $counts['expected'] => 'completed',
            default => 'incomplete',
        };

        return [
            'id' => $wfh->id,
            'request_no' => $wfh->request_no,
            'employee' => $this->formatEmployee($wfh->employee),
            'start_date' => $wfh->start_date?->toDateString(),
            'end_date' => $wfh->end_date?->toDateString(),
            'number_of_days' => $wfh->number_of_days,
            'reason' => $wfh->reason,
            'supervisor_name' => $wfh->supervisor_name,
            'request_date' => $wfh->request_date?->toDateString(),
            'remarks' => $wfh->remarks,
            'status' => $wfh->status,
            'approved_by' => $wfh->approvedBy?->name,
            'approved_at' => $wfh->approved_at?->toDateString(),
            'has_schedule' => $this->schedule->hasSchedule($wfh),
            'document_status' => $documentStatus,
            'counts' => $counts,
            'has_data' => $hasData,
        ];
    }

    /**
     * Scheduled dates merged with existing tracker rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function formatDays(WorkFromHome $wfh): array
    {
        $trackers = $wfh->trackers->keyBy(
            fn (WfhTracker $tracker): string => $tracker->date_attendance->toDateString()
        );

        return collect($this->schedule->workingDates($wfh))
            ->merge($trackers->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $date) use ($trackers): array {
                /** @var WfhTracker|null $tracker */
                $tracker = $trackers->get($date);

                return [
                    'date' => $date,
                    'tracker_id' => $tracker?->id,
                    'status' => $tracker?->status ?? 'not_submitted',
                    'remarks' => $tracker?->remarks,
                    'submitted_at' => $tracker?->submitted_at?->toIso8601String(),
                    'reviewed_at' => $tracker?->reviewed_at?->toIso8601String(),
                    'reviewed_by' => $tracker?->approvedBy?->name,
                    'document' => $tracker?->doc_path ? $this->fileInfo($tracker->doc_path) : null,
                    'images' => $tracker
                        ? $tracker->images->map(fn ($image): array => [
                            'id' => $image->id,
                            ...$this->fileInfo($image->image_path),
                        ])->values()->all()
                        : [],
                ];
            })
            ->all();
    }

    private function formatEmployee(?Employee $employee): array
    {
        $employment = $employee?->latestEmployment;

        $name = collect([
            $employee?->firstname,
            $employee?->middlename,
            $employee?->lastname,
            $employee?->suffix,
        ])->filter()->implode(' ');

        return [
            'id' => $employee?->id,
            'emp_id' => $employee?->emp_id,
            'name' => $name !== '' ? $name : 'Unknown Employee',
            // employments is the source of truth; employees.department_id is the fallback.
            'department' => $employment?->department?->department_name
                ?? $employee?->department?->department_name,
            'position' => $employment?->position?->position,
            'work_schedule' => $employment?->workSchedule?->schedule_name,
        ];
    }

    private function fileInfo(string $path): array
    {
        $cleanPath = ltrim($path, '/');
        $isPdf = str_ends_with(strtolower($cleanPath), '.pdf');
        $publicUrl = str_starts_with($path, 'http')
            ? $path
            : asset('storage/'.$cleanPath);

        $inlineUrl = $publicUrl;
        if (! $isPdf && Storage::disk('public')->exists($cleanPath)) {
            try {
                $bytes = Storage::disk('public')->get($cleanPath);
                $mime = Storage::disk('public')->mimeType($cleanPath) ?: 'image/jpeg';
                $inlineUrl = 'data:'.$mime.';base64,'.base64_encode($bytes);
            } catch (Throwable) {
                $inlineUrl = $publicUrl;
            }
        }

        return [
            'path' => $path,
            'url' => $inlineUrl,
            'public_url' => $publicUrl,
            'name' => basename($path),
            'mime' => $isPdf ? 'application/pdf' : 'image',
        ];
    }
}

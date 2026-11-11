<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TimeLog;
use App\Models\WfhImage;
use App\Models\WfhTracker;
use App\Models\WorkFromHome;
use App\Services\WfhScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * WFH Requests (admin) - work_from_home table.
 *
 * Flow: file request (pending) -> approve / reject.
 * Approved requests appear in WFH Document Review.
 */
class WfhRequestController extends Controller
{
    public function __construct(private readonly WfhScheduleService $schedule)
    {
    }

    public function index(): JsonResponse
    {
        $records = WorkFromHome::query()
            ->with($this->relations())
            ->latest('id')
            ->get()
            ->map(fn (WorkFromHome $wfh): array => $this->formatRequest($wfh))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'WFH requests retrieved successfully.',
            'data' => $records,
        ]);
    }

    /**
     * Active employees for the "New WFH Request" form.
     */
    public function employees(): JsonResponse
    {
        $employees = Employee::query()
            ->where('status', true)
            ->with($this->employeeRelations())
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get()
            ->map(fn (Employee $employee): array => $this->formatEmployee($employee))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Employees retrieved successfully.',
            'data' => $employees,
        ]);
    }

    /**
     * Number of scheduled WFH days for an employee and date range,
     * checked against the employee's assigned work schedule.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $employee = $this->activeEmployee((int) $validated['employee_id']);
        $draft = new WorkFromHome([
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
        ]);
        $draft->setRelation('employee', $employee);

        $details = $this->schedule->scheduleBreakdown($draft);

        if ($details['number_of_days'] === 0) {
            throw ValidationException::withMessages([
                'start_date' => 'The selected period has no scheduled working days for this employee.',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'WFH days calculated against employee schedule.',
            'data' => [
                'number_of_days' => $details['number_of_days'],
                'calendar_days' => $details['calendar_days'],
                'working_dates' => $details['working_dates'],
                'breakdown' => $details['breakdown'],
                'schedule_name' => $details['schedule_name'],
                'schedule_code' => $details['schedule_code'],
                'working_days' => $details['working_days'],
                'rest_days' => $details['rest_days'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'request_date' => ['nullable', 'date'],
            'supervisor_name' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $startDate = Carbon::parse($validated['start_date'])->toDateString();
        $endDate = Carbon::parse($validated['end_date'])->toDateString();

        $record = DB::transaction(function () use ($validated, $startDate, $endDate): WorkFromHome {
            // Lock the employee row so two filings for the same person cannot overlap.
            $employee = Employee::query()
                ->where('status', true)
                ->whereKey($validated['employee_id'])
                ->lockForUpdate()
                ->first();

            if (! $employee) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Select an active employee.',
                ]);
            }

            $employee->load($this->employeeRelations());
            $days = $this->countDays($employee, $startDate, $endDate);

            $overlap = WorkFromHome::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('start_date', '<=', $endDate)
                ->whereDate('end_date', '>=', $startDate)
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'start_date' => 'This employee already has a pending or approved WFH request within these dates.',
                ]);
            }

            return WorkFromHome::create([
                'employee_id' => $employee->id,
                'request_no' => $this->nextRequestNo($startDate),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'number_of_days' => $days,
                'reason' => $validated['reason'],
                'supervisor_name' => $validated['supervisor_name'],
                'request_date' => $validated['request_date'] ?? now()->toDateString(),
                'status' => 'pending',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'WFH request filed. It is now pending approval.',
            'data' => $this->formatRequest($record->fresh($this->relations())),
        ], 201);
    }

    public function approve(Request $request, WorkFromHome $workFromHome): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $record = $this->decide($request, $workFromHome, 'approved', $validated['remarks'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'WFH request approved.',
            'data' => $this->formatRequest($record),
        ]);
    }

    public function reject(Request $request, WorkFromHome $workFromHome): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:255'],
        ], [
            'remarks.required' => 'Enter the reason for rejecting this request.',
        ]);

        $record = $this->decide($request, $workFromHome, 'rejected', $validated['remarks']);

        return response()->json([
            'success' => true,
            'message' => 'WFH request rejected.',
            'data' => $this->formatRequest($record),
        ]);
    }

    public function destroy(WorkFromHome $workFromHome): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'WFH requests cannot be deleted. Cancel the request to retain its history.',
        ], 405);
    }

    // =====================================================
    // HELPERS
    // =====================================================

    private function decide(Request $request, WorkFromHome $workFromHome, string $status, ?string $remarks): WorkFromHome
    {
        return DB::transaction(function () use ($request, $workFromHome, $status, $remarks): WorkFromHome {
            $record = WorkFromHome::query()
                ->lockForUpdate()
                ->findOrFail($workFromHome->id);

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending WFH requests can be approved or rejected.',
                ]);
            }

            // Per schema, approved_by / approved_at record whoever decided (approve or reject).
            $record->update([
                'status' => $status,
                'remarks' => $remarks,
                'approved_by' => $request->user()->id,
                'approved_at' => now()->toDateString(),
            ]);

            return $record->fresh($this->relations());
        });
    }

    private function activeEmployee(int $id): Employee
    {
        $employee = Employee::query()
            ->where('status', true)
            ->with($this->employeeRelations())
            ->find($id);

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => 'Select an active employee.',
            ]);
        }

        return $employee;
    }

    private function countDays(Employee $employee, string $startDate, string $endDate): int
    {
        $draft = new WorkFromHome([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $draft->setRelation('employee', $employee);

        $days = count($this->schedule->workingDates($draft));

        if ($days === 0) {
            throw ValidationException::withMessages([
                'start_date' => 'The selected period has no scheduled working days for this employee.',
            ]);
        }

        return $days;
    }

    /**
     * WFH-{year}-{0001}. Runs inside the store transaction; the unique
     * index on request_no is the final guard against duplicates.
     */
    private function nextRequestNo(string $startDate): string
    {
        $prefix = 'WFH-'.Carbon::parse($startDate)->year.'-';

        $last = WorkFromHome::query()
            ->where('request_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('request_no')
            ->value('request_no');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<int, string>
     */
    private function employeeRelations(): array
    {
        return [
            'department:id,department_name',
            'latestEmployment',
            'latestEmployment.department:id,department_name',
            'latestEmployment.position:id,position',
            'latestEmployment.workSchedule:id,schedule_code,schedule_name',
            'latestEmployment.workSchedule.days:id,work_schedule_id,day_of_week,is_working_day,time_in,time_out',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'employee:id,emp_id,firstname,middlename,lastname,suffix,department_id,status',
            ...array_map(fn (string $relation): string => 'employee.'.$relation, $this->employeeRelations()),
            'approvedBy:id,name',
        ];
    }

    private function formatRequest(WorkFromHome $wfh): array
    {
        $details = $this->schedule->scheduleBreakdown($wfh);

        $attachmentsCount = WfhImage::whereHas('tracker', fn ($q) => $q->where('work_from_home_id', $wfh->id))->count()
            + WfhTracker::where('work_from_home_id', $wfh->id)->whereNotNull('doc_path')->count();
        $acceptedDays = WfhTracker::where('work_from_home_id', $wfh->id)->where('status', 'accepted')->count();
        $submittedDays = WfhTracker::where('work_from_home_id', $wfh->id)->where('status', 'submitted')->count();
        $timeLogsCount = TimeLog::where('employee_id', $wfh->employee_id)
            ->where('source', 'wfh')
            ->where(fn ($q) => $q->where('remarks', 'like', "%{$wfh->request_no}%")
                ->orWhere(fn ($dq) => $dq->whereDate('work_date', '>=', $wfh->start_date)->whereDate('work_date', '<=', $wfh->end_date)))
            ->count();

        $hasData = ($attachmentsCount > 0) || ($submittedDays > 0) || ($acceptedDays > 0) || ($timeLogsCount > 0);

        return [
            'id' => $wfh->id,
            'request_no' => $wfh->request_no,
            'employee' => $this->formatEmployee($wfh->employee),
            'start_date' => $wfh->start_date?->toDateString(),
            'end_date' => $wfh->end_date?->toDateString(),
            'number_of_days' => $wfh->number_of_days,
            'calendar_days' => $details['calendar_days'],
            'working_dates' => $details['working_dates'],
            'breakdown' => $details['breakdown'],
            'schedule_name' => $details['schedule_name'],
            'schedule_code' => $details['schedule_code'],
            'working_days' => $details['working_days'],
            'rest_days' => $details['rest_days'],
            'reason' => $wfh->reason,
            'supervisor_name' => $wfh->supervisor_name,
            'request_date' => $wfh->request_date?->toDateString(),
            'remarks' => $wfh->remarks,
            'status' => $wfh->status,
            'approved_by' => $wfh->approvedBy?->name,
            'approved_at' => $wfh->approved_at?->toDateString(),
            'counts' => [
                'attachments' => $attachmentsCount,
                'accepted' => $acceptedDays,
                'submitted' => $submittedDays,
                'time_logs' => $timeLogsCount,
            ],
            'has_data' => $hasData,
        ];
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
            'emp_id' => (string) ($employee?->emp_id ?? ''),
            'name' => $name !== '' ? $name : 'Unknown Employee',
            // employments is the source of truth; employees.department_id is the fallback.
            'department' => $employment?->department?->department_name
                ?? $employee?->department?->department_name,
            'position' => $employment?->position?->position,
        ];
    }
}

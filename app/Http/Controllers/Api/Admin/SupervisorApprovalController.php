<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use App\Support\DepartmentHead;
use App\Support\PrivilegeCatalog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupervisorApprovalController extends Controller
{
    private const TYPES = ['leave', 'overtime', 'undertime', 'wfh', 'oba'];

    /** Administrators see every pending request. */
    private bool $seeAll = false;

    private const TABLES = [
        'leave' => 'leave_requests',
        'overtime' => 'overtime_requests',
        'undertime' => 'undertime_requests',
        'wfh' => 'work_from_home',
        'oba' => 'oba_requests',
    ];

    private const LABELS = [
        'leave' => 'Leave',
        'overtime' => 'Overtime',
        'undertime' => 'Undertime',
        'wfh' => 'Work From Home',
        'oba' => 'Official Business',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'in:all,leave,overtime,undertime,wfh,oba'],
            'tab' => ['nullable', 'in:pending,history'],
        ]);

        $userId = (int) $request->user()->id;
        $this->seeAll = PrivilegeCatalog::isAdminRole($request->user()->role?->name);
        $departments = DepartmentHead::departmentsHeadedBy($userId);
        $tab = $validated['tab'] ?? 'pending';
        $types = ($validated['type'] ?? 'all') === 'all' ? self::TYPES : [$validated['type']];

        $items = collect($types)
            ->flatMap(fn (string $type) => $this->items($type, $userId, $tab))
            ->sortBy(fn ($i) => $tab === 'pending' ? $i['filed_at'] : '9999'.$i['acted_at'])
            ->values();

        if ($tab === 'history') {
            $items = $items->sortByDesc('acted_at')->values();
        }

        $counts = collect(self::TYPES)->mapWithKeys(fn (string $type) => [
            $type => $this->pendingQuery($type, $userId)->count(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Team approvals retrieved.',
            'data' => $items,
            'is_department_head' => true,
            'departments' => $departments->pluck('department_name')->values(),
            'sees_all' => $this->seeAll,
            'counts' => $counts->all() + ['total' => $counts->sum()],
        ]);
    }

    public function approve(Request $request, string $type, int $id): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->act($request, $type, $id, true, $validated['remarks'] ?? null);
    }

    public function reject(Request $request, string $type, int $id): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ], [
            'remarks.required' => 'Enter the reason for rejecting.',
        ]);

        return $this->act($request, $type, $id, false, $validated['remarks']);
    }

    /**
     * Names of users who give the first approval, for the "Supervisor" column
     * on HR's approval pages.
     */
    public function approvers(): JsonResponse
    {
        $ids = collect(DepartmentHead::supervisorUserIds())
            ->merge(DB::table('employees')
                ->whereIn('id', DB::table('departments')->whereNotNull('head_employee_id')->pluck('head_employee_id'))
                ->whereNotNull('user_id')
                ->pluck('user_id'))
            ->unique()
            ->values();

        $names = $ids->mapWithKeys(fn ($id) => [(int) $id => DepartmentHead::nameOfUser((int) $id)]);

        return response()->json(['success' => true, 'message' => 'Approvers retrieved.', 'data' => $names]);
    }

    /**
     * HR skips the supervisor step (e.g. the supervisor is on leave), then
     * approves as usual. Recorded as "overridden".
     */
    public function override(Request $request, string $type, int $id): JsonResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        [$module, $privilege] = match ($type) {
            'leave' => ['approvals', 'leaves.approve'],
            'overtime' => ['approvals', 'overtime.approve'],
            'undertime' => ['approvals', 'undertime.approve'],
            'wfh' => ['wfh', 'requests.approve'],
            'oba' => ['approvals', 'oba.approve'],
        };
        abort_unless($request->user()->hasPrivilege($module, $privilege), 403, 'You cannot give the final approval for this request.');

        $table = self::TABLES[$type];
        $hr = $request->user()->name;

        DB::transaction(function () use ($type, $table, $id, $hr) {
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404, 'Request not found.');

            $waiting = $type === 'oba'
                ? $row->status === 'pending'
                : $row->dept_head_status === 'pending' && $row->status === 'pending';

            if (! $waiting) {
                throw ValidationException::withMessages(['status' => 'This request is no longer waiting for a supervisor.']);
            }

            DB::table($table)->where('id', $id)->update(($type === 'oba'
                ? ['status' => 'dept_head_approved', 'dept_head_acted_at' => now()]
                : [
                    'dept_head_status' => 'overridden',
                    'dept_head_acted_at' => now(),
                    'dept_head_remarks' => "Supervisor step skipped by {$hr}.",
                ]) + ['updated_at' => now()]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Supervisor step skipped. You can now approve the request.',
            'data' => null,
        ]);
    }

    // =====================================================
    // ACTIONS
    // =====================================================

    private function act(Request $request, string $type, int $id, bool $approve, ?string $remarks): JsonResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        $userId = (int) $request->user()->id;
        $seeAll = PrivilegeCatalog::isAdminRole($request->user()->role?->name);
        $table = self::TABLES[$type];

        $row = DB::transaction(function () use ($type, $table, $id, $userId, $seeAll, $approve, $remarks) {
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();

            abort_unless($row, 404, 'Request not found.');
            abort_unless(
                $seeAll || ! $row->dept_head_id || (int) $row->dept_head_id === $userId,
                403,
                'This request is assigned to another supervisor.'
            );
            abort_if(
                (int) DB::table('employees')->where('id', $row->employee_id)->value('user_id') === $userId,
                403,
                'You cannot approve your own request.'
            );

            $isPending = $type === 'oba'
                ? $row->status === 'pending'
                : $row->dept_head_status === 'pending' && $row->status === 'pending';

            if (! $isPending) {
                throw ValidationException::withMessages(['status' => 'This request was already acted on.']);
            }

            $changes = $type === 'oba'
                ? [
                    'status' => $approve ? 'dept_head_approved' : 'rejected',
                    'dept_head_id' => $userId,
                    'dept_head_acted_at' => now(),
                    'rejection_reason' => $approve ? null : $remarks,
                ]
                : [
                    'dept_head_id' => $userId,              // who gave the first approval
                    'dept_head_status' => $approve ? 'approved' : 'rejected',
                    'dept_head_acted_at' => now(),
                    'dept_head_remarks' => $remarks,
                    'status' => $approve ? 'pending' : 'rejected',
                ];

            DB::table($table)->where('id', $id)->update($changes + ['updated_at' => now()]);

            return $row;
        });

        $this->notifyAfter($type, $row, $approve, $remarks, $userId);

        $label = self::LABELS[$type];

        return response()->json([
            'success' => true,
            'message' => $approve
                ? "{$label} request approved. It now goes to HR for final approval."
                : "{$label} request rejected.",
            'data' => null,
        ]);
    }

    private function notifyAfter(string $type, object $row, bool $approve, ?string $remarks, int $userId): void
    {
        $notifications = app(NotificationService::class);
        $label = self::LABELS[$type];
        $number = $row->request_no ?? ('#'.$row->id);
        $head = DepartmentHead::nameOfUser($userId) ?? 'The supervisor';
        $employee = $this->employeeName((int) $row->employee_id);

        if ($approve) {
            $notifications->toAdmins(
                category: 'request',
                title: "{$label} request ready for HR",
                message: "{$head} (supervisor) approved {$employee}'s {$label} request {$number}. It is waiting for HR's final approval.",
                url: self::adminUrl($type),
                exceptUserId: $userId,
            );

            return;
        }

        $notifications->toEmployee(
            employeeId: (int) $row->employee_id,
            category: 'decision',
            title: "Your {$label} request was rejected",
            message: "{$number} was rejected by {$head}. Reason: {$remarks}",
            level: 'warning',
        );
    }

    private static function adminUrl(string $type): string
    {
        return match ($type) {
            'leave' => '/admin/approvals/leaves',
            'overtime' => '/admin/approvals/overtime',
            'undertime' => '/admin/approvals/undertime',
            'wfh' => '/admin/wfh/requests',
            'oba' => '/admin/approvals/oba-request',
        };
    }

    // =====================================================
    // LISTS
    // =====================================================

    private function pendingQuery(string $type, int $userId)
    {
        // A supervisor never approves their own request.
        $ownEmployeeId = DB::table('employees')->where('user_id', $userId)->value('id');

        $query = DB::table(self::TABLES[$type])
            ->when(! $this->seeAll, fn ($q) => $q->where(fn ($w) => $w->whereNull('dept_head_id')->orWhere('dept_head_id', $userId)))
            ->when($ownEmployeeId, fn ($q) => $q->where('employee_id', '!=', $ownEmployeeId));

        return $type === 'oba'
            ? $query->where('status', 'pending')
            : $query->where('dept_head_status', 'pending')->where('status', 'pending');
    }

    private function items(string $type, int $userId, string $tab): Collection
    {
        $table = self::TABLES[$type];

        $query = $tab === 'pending'
            ? $this->pendingQuery($type, $userId)
            : DB::table($table)
                ->when(! $this->seeAll, fn ($q) => $q->where('dept_head_id', $userId))
                ->when(
                    $type === 'oba',
                    fn ($q) => $q->whereNotNull('dept_head_acted_at'),
                    fn ($q) => $q->whereIn('dept_head_status', ['approved', 'rejected', 'overridden']),
                )
                ->orderByDesc('dept_head_acted_at')
                ->limit(50);

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $employees = DB::table('employees')
            ->whereIn('id', $rows->pluck('employee_id'))
            ->get(['id', 'emp_id', 'firstname', 'lastname'])
            ->keyBy('id');

        $details = $this->details($type, $rows);

        return $rows->map(function ($row) use ($type, $employees, $details) {
            $employee = $employees->get($row->employee_id);

            return [
                'type' => $type,
                'type_label' => self::LABELS[$type],
                'id' => $row->id,
                'request_no' => $row->request_no ?? ('OBA-'.str_pad((string) $row->id, 4, '0', STR_PAD_LEFT)),
                'employee' => $employee ? trim($employee->firstname.' '.$employee->lastname) : 'Unknown',
                'emp_id' => $employee?->emp_id,
                'summary' => $details[$row->id] ?? '',
                'reason' => $row->reason ?? null,
                'filed_at' => (string) $row->created_at,
                'status' => $row->status,
                'head_by' => DepartmentHead::nameOfUser($row->dept_head_id ? (int) $row->dept_head_id : null),
                'head_status' => $type === 'oba'
                    ? ($row->status === 'pending' ? 'pending' : ($row->status === 'rejected' && $row->dept_head_acted_at ? 'rejected' : 'approved'))
                    : $row->dept_head_status,
                'head_remarks' => $type === 'oba' ? ($row->rejection_reason ?? null) : ($row->dept_head_remarks ?? null),
                'acted_at' => $row->dept_head_acted_at ? (string) $row->dept_head_acted_at : null,
            ];
        });
    }

    /**
     * One-line description per request: dates, times and type.
     *
     * @return array<int, string>
     */
    private function details(string $type, Collection $rows): array
    {
        $fmt = fn ($d, $f = 'M j, Y') => $d ? Carbon::parse($d)->format($f) : '—';

        if ($type === 'leave') {
            $types = DB::table('leave_types')->whereIn('id', $rows->pluck('leave_type_id'))->pluck('leave_name', 'id');
            $dates = DB::table('leave_request_dates')
                ->whereIn('leave_request_id', $rows->pluck('id'))
                ->groupBy('leave_request_id')
                ->selectRaw('leave_request_id, MIN(leave_date) AS first_day, MAX(leave_date) AS last_day, SUM(day_value) AS days')
                ->get()
                ->keyBy('leave_request_id');

            return $rows->mapWithKeys(function ($r) use ($types, $dates, $fmt) {
                $d = $dates->get($r->id);
                $range = $d
                    ? ($d->first_day === $d->last_day ? $fmt($d->first_day) : $fmt($d->first_day, 'M j').' – '.$fmt($d->last_day))
                    : '—';
                $days = $d ? rtrim(rtrim(number_format((float) $d->days, 2), '0'), '.') : '0';

                return [$r->id => ($types[$r->leave_type_id] ?? 'Leave').' · '.$range." ({$days} day".($days === '1' ? '' : 's').')'];
            })->all();
        }

        if ($type === 'overtime') {
            return $rows->mapWithKeys(function ($r) use ($fmt) {
                $hours = round(Carbon::parse($r->start_at)->diffInMinutes(Carbon::parse($r->end_at)) / 60, 2);

                return [$r->id => $fmt($r->ot_date).' · '.$fmt($r->start_at, 'g:i A').' – '.$fmt($r->end_at, 'g:i A')." ({$hours}h)"];
            })->all();
        }

        if ($type === 'undertime') {
            return $rows->mapWithKeys(function ($r) use ($fmt) {
                $hours = round(((int) $r->requested_minutes) / 60, 2);

                return [$r->id => $fmt($r->undertime_date).' · leave at '.$fmt($r->requested_time_out, 'g:i A').' instead of '.$fmt($r->scheduled_time_out, 'g:i A')." ({$hours}h)"];
            })->all();
        }

        if ($type === 'wfh') {
            return $rows->mapWithKeys(function ($r) use ($fmt) {
                $range = $r->start_date === $r->end_date
                    ? $fmt($r->start_date)
                    : $fmt($r->start_date, 'M j').' – '.$fmt($r->end_date);
                $days = (int) $r->number_of_days;

                return [$r->id => "Work from home · {$range} ({$days} day".($days === 1 ? '' : 's').')'];
            })->all();
        }

        return $rows->mapWithKeys(fn ($r) => [
            $r->id => ($r->destination ?? 'Official business').' · '.$fmt($r->time_from, 'M j, g:i A').' – '.$fmt($r->time_to, 'g:i A'),
        ])->all();
    }

    private function employeeName(int $employeeId): string
    {
        $e = DB::table('employees')->where('id', $employeeId)->first(['firstname', 'lastname']);

        return $e ? trim($e->firstname.' '.$e->lastname) : 'An employee';
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PendingRequestController extends Controller
{
    /**
     * type => [label, page where it is reviewed]
     */
    private const TYPES = [
        'leave' => ['Leave Request', '/admin/approvals/leaves'],
        'overtime' => ['Overtime Request', '/admin/approvals/overtime'],
        'undertime' => ['Undertime Request', '/admin/approvals/undertime'],
        'time_correction' => ['Time Log Correction', '/admin/timekeeping/time-log-correction-requests'],
        'wfh' => ['WFH Request', '/admin/wfh/requests'],
        'rest_day_switch' => ['Rest Day Switch', '/admin/switch-rest-day'],
        'salary_adjustment' => ['Salary Adjustment', '/admin/employees/salary-adjustments'],
    ];

    // =====================================================
    // DASHBOARD CARD
    // =====================================================

    public function summary(): JsonResponse
    {
        $types = collect(self::TYPES)->map(function (array $meta, string $type) {
            $query = $this->baseQuery($type);

            $count = $query ? (clone $query)->count() : 0;
            $oldest = $query && $count ? (clone $query)->min($this->createdColumn($type)) : null;

            return [
                'type' => $type,
                'label' => $meta[0],
                'url' => $meta[1],
                'count' => $count,
                'oldest_at' => $oldest ? Carbon::parse($oldest)->toIso8601String() : null,
                'oldest_days' => $oldest ? (int) Carbon::parse($oldest)->startOfDay()->diffInDays(today()) : null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Pending requests summary retrieved.',
            'data' => [
                'total' => $types->sum('count'),
                'types' => $types,
            ],
        ]);
    }

    // =====================================================
    // VIEW ALL
    // =====================================================

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', array_keys(self::TYPES))],
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'in:oldest,newest'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $types = isset($validated['type']) ? [$validated['type']] : array_keys(self::TYPES);
        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));
        $departmentId = $validated['department_id'] ?? null;

        // Pending requests are few at any time, so each module is read
        // separately and the combined list is sorted and paged here.
        $items = collect($types)
            ->flatMap(fn (string $type) => $this->items($type))
            ->when($departmentId, fn (Collection $c) => $c->where('employee.department_id', (int) $departmentId))
            ->when($search !== '', fn (Collection $c) => $c->filter(fn (array $item) => str_contains(
                mb_strtolower($item['request_no'].' '.$item['employee']['name'].' '.$item['employee']['emp_id'].' '.$item['details']),
                $search
            )))
            ->sortBy('filed_at', SORT_REGULAR, ($validated['sort'] ?? 'oldest') === 'newest')
            ->values();

        $perPage = (int) ($validated['per_page'] ?? 15);
        $page = (int) ($validated['page'] ?? 1);
        $lastPage = max(1, (int) ceil($items->count() / $perPage));
        $page = min($page, $lastPage);
        $pageItems = $items->forPage($page, $perPage)->values();

        return response()->json([
            'success' => true,
            'message' => 'Pending requests retrieved.',
            'data' => $pageItems,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $items->count(),
                'from' => $items->isEmpty() ? 0 : ($page - 1) * $perPage + 1,
                'to' => ($page - 1) * $perPage + $pageItems->count(),
            ],
            'departments' => DB::table('departments')
                ->orderBy('department_name')
                ->get(['id', 'department_name as name']),
        ]);
    }

    // =====================================================
    // QUERIES PER TYPE
    // =====================================================

    private function table(string $type): string
    {
        return [
            'leave' => 'leave_requests',
            'overtime' => 'overtime_requests',
            'undertime' => 'undertime_requests',
            'time_correction' => 'time_log_correction_requests',
            'wfh' => 'work_from_homes',
            'rest_day_switch' => 'rest_day_switch_requests',
            'salary_adjustment' => 'salary_adjustments',
        ][$type];
    }

    private function createdColumn(string $type): string
    {
        return $this->table($type).'.created_at';
    }

    /**
     * Pending rows of one type, joined to the employee and department.
     * Returns null when the module's table does not exist yet.
     */
    private function baseQuery(string $type): ?Builder
    {
        $table = $this->table($type);

        if (! Schema::hasTable($table)) {
            return null;
        }

        return DB::table($table)
            ->join('employees', 'employees.id', '=', "{$table}.employee_id")
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->where("{$table}.status", 'pending');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function items(string $type): Collection
    {
        $query = $this->baseQuery($type);

        if (! $query) {
            return collect();
        }

        $table = $this->table($type);

        $query->select([
            "{$table}.*",
            'employees.emp_id',
            'employees.firstname',
            'employees.middlename',
            'employees.lastname',
            'employees.department_id',
            'departments.department_name',
        ]);

        $extra = [];

        if ($type === 'leave') {
            $query->leftJoin('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
                ->addSelect('leave_types.leave_name');

            $extra = DB::table('leave_request_dates')
                ->whereIn('leave_request_id', (clone $query)->pluck('leave_requests.id'))
                ->groupBy('leave_request_id')
                ->selectRaw('leave_request_id, MIN(leave_date) AS first_day, MAX(leave_date) AS last_day, SUM(day_value) AS days')
                ->get()
                ->keyBy('leave_request_id');
        }

        if ($type === 'time_correction') {
            $query->leftJoin('time_logs', 'time_logs.id', '=', 'time_log_correction_requests.time_log_id')
                ->addSelect('time_logs.work_date');
        }

        return $query->get()->map(fn (object $row) => $this->format($type, $row, $extra));
    }

    // =====================================================
    // FORMAT
    // =====================================================

    private function format(string $type, object $row, $extra): array
    {
        [$label, $url] = self::TYPES[$type];
        $filed = Carbon::parse($row->created_at);

        [$requestNo, $date, $details] = match ($type) {
            'leave' => $this->leaveDetails($row, $extra[$row->id] ?? null),
            'overtime' => [
                $row->request_no,
                $row->ot_date,
                $this->day($row->ot_date).' · '.$this->time($row->start_at).'–'.$this->time($row->end_at)
                    .' ('.$this->hours(Carbon::parse($row->start_at)->diffInMinutes(Carbon::parse($row->end_at))).')',
            ],
            'undertime' => [
                $row->request_no,
                $row->undertime_date,
                $this->day($row->undertime_date).' · out at '.$this->time($row->requested_time_out)
                    .' ('.$this->hours((int) $row->requested_minutes).' early)',
            ],
            'time_correction' => [
                'TLC-'.str_pad((string) $row->id, 5, '0', STR_PAD_LEFT),
                $row->work_date,
                ($row->correction_type === 'time_in' ? 'Time in' : 'Time out')
                    .' → '.Carbon::parse($row->requested_time)->format('g:i A')
                    .($row->work_date ? ' · '.$this->day($row->work_date) : ''),
            ],
            'wfh' => [
                $row->request_no,
                $row->start_date,
                $this->range($row->start_date, $row->end_date).' ('.$row->number_of_days.' day'.($row->number_of_days == 1 ? '' : 's').')',
            ],
            'rest_day_switch' => [
                $row->request_number,
                $row->effective_date,
                ucfirst($row->current_rest_day).' → '.ucfirst($row->requested_rest_day).' · effective '.$this->day($row->effective_date),
            ],
            'salary_adjustment' => [
                'SA-'.str_pad((string) $row->id, 5, '0', STR_PAD_LEFT),
                $row->effective_date,
                ucfirst($row->adjustment_type).' · ₱'.number_format((float) $row->previous_basic_salary, 2)
                    .' → ₱'.number_format((float) $row->new_basic_salary, 2)
                    .' · effective '.$this->day($row->effective_date),
            ],
        };

        return [
            'key' => "{$type}-{$row->id}",
            'id' => $row->id,
            'type' => $type,
            'type_label' => $label,
            'request_no' => $requestNo,
            'details' => $details,
            'date' => $date ? Carbon::parse($date)->toDateString() : null,
            'reason' => $row->reason ?? null,
            'employee' => [
                'id' => $row->employee_id,
                'emp_id' => (string) ($row->emp_id ?? ''),
                'name' => trim(implode(' ', array_filter([$row->firstname, $row->middlename, $row->lastname]))),
                'department_id' => $row->department_id ? (int) $row->department_id : null,
                'department' => $row->department_name,
            ],
            'filed_at' => $filed->toIso8601String(),
            'waiting_days' => (int) $filed->copy()->startOfDay()->diffInDays(today()),
            'url' => $url,
        ];
    }

    private function leaveDetails(object $row, ?object $dates): array
    {
        $name = $row->leave_name ?? 'Leave';

        if (! $dates) {
            return [$row->request_no, $row->request_date, $name];
        }

        $days = rtrim(rtrim(number_format((float) $dates->days, 2, '.', ''), '0'), '.');

        return [
            $row->request_no,
            $dates->first_day,
            $name.' · '.$this->range($dates->first_day, $dates->last_day).' ('.$days.' day'.($days === '1' ? '' : 's').')',
        ];
    }

    private function day(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('M j, Y') : '—';
    }

    private function time(?string $dateTime): string
    {
        return $dateTime ? Carbon::parse($dateTime)->format('g:i A') : '—';
    }

    private function range(?string $start, ?string $end): string
    {
        if (! $start) {
            return '—';
        }

        $a = Carbon::parse($start);
        $b = Carbon::parse($end ?: $start);

        if ($a->isSameDay($b)) {
            return $a->format('M j, Y');
        }

        return $a->isSameYear($b)
            ? $a->format('M j').'–'.$b->format($a->isSameMonth($b) ? 'j, Y' : 'M j, Y')
            : $a->format('M j, Y').'–'.$b->format('M j, Y');
    }

    private function hours(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h ? "{$h}h " : '').($m ? "{$m}m" : '')) ?: '0m';
    }
}
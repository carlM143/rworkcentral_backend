<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpcomingEventController extends Controller
{
    private const PROBATION_MONTHS = 6;

    private const TYPES = ['birthday', 'anniversary', 'regularization', 'holiday', 'milestone'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'month' => ['nullable', 'date_format:Y-m'],
            'types' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        // Date range: a whole month, or today + N days.
        if (! empty($validated['month'])) {
            $from = Carbon::createFromFormat('!Y-m', $validated['month'])->startOfMonth()->startOfDay();
            $to = $from->copy()->endOfMonth()->startOfDay();
        } else {
            $from = today();
            $to = today()->addDays((int) ($validated['days'] ?? 30));
        }

        $types = collect(explode(',', (string) ($validated['types'] ?? '')))
            ->map(fn ($t) => trim($t))
            ->filter(fn ($t) => in_array($t, self::TYPES, true))
            ->values();
        $types = $types->isEmpty() ? collect(self::TYPES) : $types;

        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;
        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));

        $all = collect()
            ->merge($this->birthdays($from, $to))
            ->merge($this->anniversaries($from, $to))
            ->merge($this->regularizations($from, $to))
            ->merge($this->holidays($from, $to))
            ->merge($this->milestones($from, $to))
            // Holidays apply to everyone, so they stay when filtering by department.
            ->when($departmentId, fn (Collection $c) => $c->filter(
                fn (array $e) => $e['type'] === 'holiday' || $e['department_id'] === $departmentId
            ))
            ->when($search !== '', fn (Collection $c) => $c->filter(
                fn (array $e) => str_contains(mb_strtolower($e['title'].' '.$e['detail'].' '.($e['department'] ?? '')), $search)
            ))
            ->sortBy([['date', 'asc'], ['priority', 'asc'], ['title', 'asc']])
            ->values();

        // Counts per type ignore the type filter, so tabs show their totals.
        $counts = collect(self::TYPES)->mapWithKeys(fn ($t) => [$t => $all->where('type', $t)->count()]);
        $events = $all->whereIn('type', $types->all())->values();

        $clean = fn (Collection $items) => $items
            ->map(fn (array $e) => collect($e)->except(['priority'])->all())
            ->values();

        $data = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => $events->count(),
            'counts' => $counts,
        ];

        // View all page: paginated. Dashboard card: first N.
        if (isset($validated['page'])) {
            $perPage = (int) ($validated['per_page'] ?? 20);
            $lastPage = max(1, (int) ceil($events->count() / $perPage));
            $page = min((int) $validated['page'], $lastPage);
            $pageItems = $events->forPage($page, $perPage)->values();

            $data['items'] = $clean($pageItems);
            $data['meta'] = [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $events->count(),
                'from' => $events->isEmpty() ? 0 : ($page - 1) * $perPage + 1,
                'to' => ($page - 1) * $perPage + $pageItems->count(),
            ];
            $data['departments'] = DB::table('departments')
                ->orderBy('department_name')
                ->get(['id', 'department_name as name']);
        } else {
            $data['items'] = $clean($events->take((int) ($validated['limit'] ?? 6)));
        }

        return response()->json([
            'success' => true,
            'message' => 'Upcoming events retrieved.',
            'data' => $data,
        ]);
    }

    // =====================================================
    // SOURCES
    // =====================================================

    private ?Collection $employees = null;

    /**
     * Active employees with their latest employment (date hired, type).
     * Loaded once per request.
     */
    private function activeEmployees(): Collection
    {
        return $this->employees ??= DB::table('employees')
            ->leftJoin('employments', function ($join) {
                $join->on('employments.employee_id', '=', 'employees.id')
                    ->whereRaw('employments.id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employees.id)');
            })
            ->leftJoin('employment_types', 'employment_types.id', '=', 'employments.employment_type_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->where('employees.status', true)
            ->get([
                'employees.id',
                'employees.emp_id',
                'employees.firstname',
                'employees.lastname',
                'employees.birthday',
                'employees.department_id',
                'departments.department_name',
                'employments.date_hired',
                'employment_types.name as employment_type',
            ]);
    }

    private function birthdays(Carbon $from, Carbon $to): Collection
    {
        return $this->activeEmployees()
            ->filter(fn ($e) => $e->birthday)
            ->map(function ($e) use ($from, $to) {
                $date = $this->nextOccurrence(Carbon::parse($e->birthday), $from);

                if ($date->lt($from) || $date->gt($to)) {
                    return null;
                }

                $age = (int) Carbon::parse($e->birthday)->diffInYears($date);

                return $this->event('birthday', 'Employee Birthday', $this->name($e), $date, "Turns {$age}", 2, $e->id, $e);
            })
            ->filter();
    }

    private function anniversaries(Carbon $from, Carbon $to): Collection
    {
        return $this->activeEmployees()
            ->filter(fn ($e) => $e->date_hired)
            ->map(function ($e) use ($from, $to) {
                $hired = Carbon::parse($e->date_hired);
                $date = $this->nextOccurrence($hired, $from);
                $years = (int) $hired->diffInYears($date);

                if ($years < 1 || $date->lt($from) || $date->gt($to)) {
                    return null;
                }

                return $this->event(
                    'anniversary',
                    'Work Anniversary',
                    $this->name($e),
                    $date,
                    $years.' year'.($years > 1 ? 's' : '').' with the company',
                    3,
                    $e->id,
                    $e
                );
            })
            ->filter();
    }

    private function regularizations(Carbon $from, Carbon $to): Collection
    {
        return $this->activeEmployees()
            ->filter(fn ($e) => $e->date_hired && $e->employment_type && str_contains(mb_strtolower($e->employment_type), 'probation'))
            ->map(function ($e) use ($from, $to) {
                $date = Carbon::parse($e->date_hired)->addMonthsNoOverflow(self::PROBATION_MONTHS)->startOfDay();

                if ($date->lt($from) || $date->gt($to)) {
                    return null;
                }

                return $this->event(
                    'regularization',
                    'Regularization',
                    $this->name($e),
                    $date,
                    self::PROBATION_MONTHS.' months since hired '.Carbon::parse($e->date_hired)->format('M j, Y'),
                    1,
                    $e->id,
                    $e
                );
            })
            ->filter();
    }

    private function holidays(Carbon $from, Carbon $to): Collection
    {
        if (! Schema::hasTable('holidays')) {
            return collect();
        }

        $base = DB::table('holidays')
            ->leftJoin('holiday_types', 'holiday_types.id', '=', 'holidays.holiday_type_id')
            ->where('holidays.status', true);

        $rows = (clone $base)
            ->whereBetween('holidays.holiday_date', [$from->toDateString(), $to->toDateString()])
            ->get(['holidays.id', 'holidays.holiday_name', 'holidays.holiday_date as date', 'holiday_types.type_name']);

        // Extra dates of multi-day holidays.
        if (Schema::hasTable('holiday_dates')) {
            $rows = $rows->merge(
                (clone $base)
                    ->join('holiday_dates', 'holiday_dates.holiday_id', '=', 'holidays.id')
                    ->whereBetween('holiday_dates.holiday_date', [$from->toDateString(), $to->toDateString()])
                    ->get(['holidays.id', 'holidays.holiday_name', 'holiday_dates.holiday_date as date', 'holiday_types.type_name'])
            );
        }

        return $rows
            ->unique(fn ($h) => $h->id.'|'.$h->date)
            ->map(fn ($h) => $this->event(
                'holiday',
                'Holiday',
                $h->holiday_name,
                Carbon::parse($h->date),
                $h->type_name ?: 'Holiday',
                0,
                $h->id
            ));
    }

    private function milestones(Carbon $from, Carbon $to): Collection
    {
        if (! Schema::hasTable('employee_lifecycle_milestones')) {
            return collect();
        }

        return DB::table('employee_lifecycle_milestones')
            ->join('employees', 'employees.id', '=', 'employee_lifecycle_milestones.employee_id')
            ->leftJoin('employee_lifecycle_stages', 'employee_lifecycle_stages.id', '=', 'employee_lifecycle_milestones.lifecycle_stage_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->where('employee_lifecycle_milestones.status', 'pending')
            ->where('employees.status', true)
            ->whereBetween('employee_lifecycle_milestones.due_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get([
                'employee_lifecycle_milestones.id',
                'employee_lifecycle_milestones.due_date',
                'employees.emp_id',
                'employees.firstname',
                'employees.lastname',
                'employees.department_id',
                'departments.department_name',
                'employee_lifecycle_stages.stage_name',
            ])
            ->map(fn ($m) => $this->event(
                'milestone',
                $m->stage_name ?: 'Lifecycle Milestone',
                $this->name($m),
                Carbon::parse($m->due_date)->startOfDay(),
                'Lifecycle milestone due',
                1,
                $m->id,
                $m
            ));
    }

    // =====================================================
    // HELPERS
    // =====================================================

    /**
     * This year's (or next year's) date of a yearly event, from $from on.
     * Feb 29 falls on Feb 28 in non-leap years.
     */
    private function nextOccurrence(Carbon $original, Carbon $from): Carbon
    {
        $date = $this->onYear($original, $from->year);

        return $date->lt($from) ? $this->onYear($original, $from->year + 1) : $date;
    }

    private function onYear(Carbon $original, int $year): Carbon
    {
        $day = min($original->day, Carbon::create($year, $original->month, 1)->daysInMonth);

        return Carbon::create($year, $original->month, $day)->startOfDay();
    }

    private function name(object $row): string
    {
        return trim(($row->firstname ?? '').' '.($row->lastname ?? ''));
    }

    private function event(
        string $type,
        string $label,
        string $title,
        Carbon $date,
        string $detail,
        int $priority,
        int $sourceId,
        ?object $employee = null
    ): array {
        return [
            'key' => "{$type}-{$sourceId}-{$date->toDateString()}",
            'type' => $type,
            'label' => $label,
            'title' => $title,
            'detail' => $detail,
            'date' => $date->toDateString(),
            // Negative for dates already passed (month view).
            'days_until' => (int) today()->diffInDays($date, false),
            'emp_id' => $employee?->emp_id !== null ? (string) $employee->emp_id : null,
            'department_id' => $employee?->department_id ? (int) $employee->department_id : null,
            'department' => $employee?->department_name ?? null,
            'priority' => $priority,
        ];
    }
}
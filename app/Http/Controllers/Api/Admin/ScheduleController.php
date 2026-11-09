<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkSchedule;
use App\Services\WorkScheduleRevisionService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    private const RELATIONS = [
        'scheduleType:id,type_name',
        'days',
        'currentRevision',
        'upcomingRevision.days',
    ];

    public function __construct(private WorkScheduleRevisionService $revisions) {}

    // GET ALL WORK SCHEDULES
    public function index(Request $request)
    {
        $query = WorkSchedule::query()
            ->with(self::RELATIONS)
            ->withCount('employments');

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('schedule_name', 'like', "%{$search}%")
                    ->orWhere('schedule_code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('schedule_type_id')) {
            $query->where('schedule_type_id', $request->schedule_type_id);
        }

        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->boolean('status'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Work schedules retrieved successfully.',
            'data' => $query->latest('id')->get(),
        ]);
    }

    // CREATE WORK SCHEDULE WITH ITS FIRST REVISION
    public function store(Request $request)
    {
        $validated = $this->validateSchedule($request);
        [$master, $config, $days, $meta] = $this->splitPayload($validated);

        $schedule = DB::transaction(function () use ($master, $config, $days, $meta) {
            $schedule = WorkSchedule::create($master + $config);

            $revision = $this->revisions->create(
                $schedule, $config, $days,
                $meta['effective_from'] ?? today()->toDateString(),
                $meta['reason'] ?? 'Initial schedule'
            );

            $this->revisions->applyToSchedule($schedule, $revision);

            return $schedule;
        });

        return response()->json([
            'success' => true,
            'message' => 'Work schedule created successfully.',
            'data' => $schedule->fresh()->load(self::RELATIONS)->loadCount('employments'),
        ], 201);
    }

    // SHOW WORK SCHEDULE WITH CURRENT, UPCOMING AND HISTORY
    public function show(WorkSchedule $workSchedule)
    {
        $workSchedule
            ->load([
                'scheduleType:id,type_name',
                'days',
                'revisions' => fn ($q) => $q->orderByDesc('effective_from')->orderByDesc('id')->with('days'),
            ])
            ->loadCount('employments');

        $today = today();

        [$upcoming, $started] = $workSchedule->revisions
            ->partition(fn ($r) => $r->effective_from->gt($today));

        $upcoming = $upcoming->sortBy('effective_from')->values();
        $current  = $started->first();
        $history  = $started->slice(1)->values();

        // Same keys as index() for current/upcoming, plus the full breakdown
        $workSchedule
            ->unsetRelation('revisions')
            ->setRelation('currentRevision', $current)
            ->setRelation('upcomingRevision', $upcoming->first());

        return response()->json([
            'success' => true,
            'message' => 'Work schedule retrieved successfully.',
            'data' => array_merge($workSchedule->toArray(), [
                'upcoming_revisions' => $upcoming,
                'revision_history'   => $history,
            ]),
        ]);
    }

    // UPDATE: master fields apply now, hour/day changes become a dated revision
    public function update(Request $request, WorkSchedule $workSchedule)
    {
        $validated = $this->validateSchedule($request, $workSchedule);
        [$master, $config, $days, $meta] = $this->splitPayload($validated);

        $revision = DB::transaction(function () use ($workSchedule, $master, $config, $days, $meta) {
            $workSchedule->update($master);

            $latest = $workSchedule->revisions()
                ->orderByDesc('effective_from')
                ->with('days')
                ->first();

            if (! $this->revisions->hasChanged($latest, $config, $days)) {
                return null;
            }

            if (empty($meta['effective_from'])) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Effective date is required when changing working hours, grace period, or days.',
                ]);
            }

            $revision = $this->revisions->change(
                $workSchedule, $config, $days, $meta['effective_from'], $meta['reason'] ?? null
            );

            if (! $revision->effective_from->isFuture()) {
                $this->revisions->applyToSchedule($workSchedule, $revision);
            }

            return $revision;
        });

        $message = $revision && $revision->effective_from->isFuture()
            ? "Schedule change saved. It takes effect on {$revision->effective_from->format('M j, Y')}."
            : 'Work schedule updated successfully.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $workSchedule->fresh()->load(self::RELATIONS)->loadCount('employments'),
        ]);
    }

    // DELETE WORK SCHEDULE
    public function destroy(WorkSchedule $workSchedule)
    {
        if ($workSchedule->employments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this schedule because it is assigned to existing employees.',
            ], 409);
        }

        $workSchedule->delete(); // revisions cascade

        return response()->json([
            'success' => true,
            'message' => 'Work schedule deleted successfully.',
        ]);
    }

    private function splitPayload(array $validated): array
    {
        return [
            Arr::only($validated, ['schedule_type_id', 'schedule_code', 'schedule_name', 'status']),
            Arr::only($validated, ['required_work_hours', 'grace_period']) + ['break_start' => null, 'break_end' => null],
            $validated['days'],
            Arr::only($validated, ['effective_from', 'reason']),
        ];
    }

    // VALIDATE PARENT SCHEDULE, DAYS AND EFFECTIVE DATE
    private function validateSchedule(Request $request, ?WorkSchedule $workSchedule = null): array
    {
        $validated = $request->validate([
            'schedule_type_id' => ['required', 'integer', Rule::exists('schedule_types', 'id')],
            'schedule_code' => [
                'required', 'string', 'max:50',
                Rule::unique('work_schedules', 'schedule_code')->ignore($workSchedule?->id),
            ],
            'schedule_name' => ['required', 'string', 'max:255'],
            'required_work_hours' => ['required', 'numeric', 'gt:0', 'lte:24'],
            'grace_period' => ['required', 'integer', 'between:0,120'],
            'status' => ['required', 'boolean'],

            'effective_from' => $workSchedule
                ? ['nullable', 'date', 'after_or_equal:today']
                : ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],

            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*.day_of_week' => [
                'required',
                Rule::in(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']),
            ],
            'days.*.is_working_day' => ['sometimes', 'boolean'],
            'days.*.time_in' => ['nullable', 'date_format:H:i'],
            'days.*.time_out' => ['nullable', 'date_format:H:i'],
        ]);

        $days = $validated['days'];
        $dayNames = array_column($days, 'day_of_week');

        if (count(array_unique($dayNames)) !== count($dayNames)) {
            throw ValidationException::withMessages([
                'days' => 'Each supplied day may appear only once.',
            ]);
        }

        $workingDays = [];

        foreach ($days as $index => $day) {
            if (! (bool) ($day['is_working_day'] ?? true)) {
                continue;
            }

            $dayName = $day['day_of_week'];

            if (empty($day['time_in']) || empty($day['time_out'])) {
                throw ValidationException::withMessages([
                    "days.{$index}.time_in" => "{$dayName} requires both Time In and Time Out.",
                ]);
            }

            if ($day['time_in'] === $day['time_out']) {
                throw ValidationException::withMessages([
                    "days.{$index}.time_out" => "{$dayName} Time Out must be different from Time In.",
                ]);
            }

            $workingDays[] = [
                'day_of_week' => $dayName,
                'is_working_day' => true,
                'time_in' => $day['time_in'],
                'time_out' => $day['time_out'],
            ];
        }

        if (empty($workingDays)) {
            throw ValidationException::withMessages([
                'days' => 'At least one working day must be selected.',
            ]);
        }

        $validated['days'] = $workingDays;

        return $validated;
    }
}
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    // GET ALL WORK SCHEDULES
    public function index(Request $request)
    {
        $query = WorkSchedule::query()
            ->with(['scheduleType:id,type_name', 'days'])
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

    // CREATE WORK SCHEDULE AND SEVEN DAYS
    public function store(Request $request)
    {
        $validated = $this->validateSchedule($request);

        $days = $validated['days'];
        unset($validated['days']);

        // Existing nullable columns in the parent table.
        $validated['break_start'] = null;
        $validated['break_end'] = null;

        $schedule = DB::transaction(function () use ($validated, $days) {
            $schedule = WorkSchedule::create($validated);

            foreach ($days as $day) {
                $schedule->days()->create([
                    'day_of_week' => $day['day_of_week'],
                    'is_working_day' => $day['is_working_day'],
                    'time_in' => $day['time_in'],
                    'time_out' => $day['time_out'],
                ]);
            }

            return $schedule;
        });

        return response()->json([
            'success' => true,
            'message' => 'Work schedule created successfully.',
            'data' => $schedule
                ->load(['scheduleType:id,type_name', 'days'])
                ->loadCount('employments'),
        ], 201);
    }

    // SHOW WORK SCHEDULE WITH DAILY CONFIGURATIONS
    public function show(WorkSchedule $workSchedule)
    {
        return response()->json([
            'success' => true,
            'message' => 'Work schedule retrieved successfully.',
            'data' => $workSchedule
                ->load(['scheduleType:id,type_name', 'days'])
                ->loadCount('employments'),
        ]);
    }

    // UPDATE WORK SCHEDULE AND SEVEN DAYS
    public function update(Request $request, WorkSchedule $workSchedule)
    {
        $validated = $this->validateSchedule($request, $workSchedule);

        $days = $validated['days'];
        unset($validated['days']);

        $validated['break_start'] = null;
        $validated['break_end'] = null;

        DB::transaction(function () use ($workSchedule, $validated, $days) {
            $workSchedule->update($validated);

            foreach ($days as $day) {
                $workSchedule->days()->updateOrCreate(
                    [
                        'day_of_week' => $day['day_of_week'],
                    ],
                    [
                        'is_working_day' => $day['is_working_day'],
                        'time_in' => $day['time_in'],
                        'time_out' => $day['time_out'],
                    ]
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Work schedule updated successfully.',
            'data' => $workSchedule
                ->fresh()
                ->load(['scheduleType:id,type_name', 'days'])
                ->loadCount('employments'),
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

        $workSchedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Work schedule deleted successfully.',
        ]);
    }

    // VALIDATE PARENT SCHEDULE AND ALL SEVEN DAYS
    private function validateSchedule(
        Request $request,
        ?WorkSchedule $workSchedule = null
    ): array {
        $validated = $request->validate([
            'schedule_type_id' => [
                'required',
                'integer',
                Rule::exists('schedule_types', 'id'),
            ],
            'schedule_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('work_schedules', 'schedule_code')
                    ->ignore($workSchedule?->id),
            ],
            'schedule_name' => ['required', 'string', 'max:255'],
            'required_work_hours' => ['required', 'numeric', 'gt:0', 'lte:24'],
            'grace_period' => ['required', 'integer', 'between:0,120'],
            'status' => ['required', 'boolean'],

            'days' => ['required', 'array', 'size:7'],

            'days.*.day_of_week' => [
                'required',
                Rule::in([
                    'Monday',
                    'Tuesday',
                    'Wednesday',
                    'Thursday',
                    'Friday',
                    'Sat',
                    'Sun',
                ]),
            ],
            'days.*.is_working_day' => ['required', 'boolean'],
            'days.*.time_in' => ['nullable', 'date_format:H:i'],
            'days.*.time_out' => ['nullable', 'date_format:H:i'],
        ]);

        $days = $validated['days'];

        // Require exactly one record for each day.
        $dayNames = array_column($days, 'day_of_week');

        if (count(array_unique($dayNames)) !== 7) {
            throw ValidationException::withMessages([
                'days' => 'Each day from Monday to Sunday must appear exactly once.',
            ]);
        }

        $workingDays = 0;

        foreach ($days as $index => $day) {
            $isWorkingDay = (bool) $day['is_working_day'];
            $dayName = $day['day_of_week'];

            if ($isWorkingDay) {
                $workingDays++;

                if (empty($day['time_in']) || empty($day['time_out'])) {
                    throw ValidationException::withMessages([
                        "days.{$index}.time_in" =>
                            "{$dayName} requires both Time In and Time Out.",
                    ]);
                }

                if ($day['time_in'] === $day['time_out']) {
                    throw ValidationException::withMessages([
                        "days.{$index}.time_out" =>
                            "{$dayName} Time Out must be different from Time In.",
                    ]);
                }

                $days[$index]['time_in'] = $day['time_in'];
                $days[$index]['time_out'] = $day['time_out'];
            } else {
                // Rest days should not store working times.
                $days[$index]['time_in'] = null;
                $days[$index]['time_out'] = null;
            }

            $days[$index]['is_working_day'] = $isWorkingDay;
        }

        if ($workingDays === 0) {
            throw ValidationException::withMessages([
                'days' => 'At least one working day must be selected.',
            ]);
        }

        $validated['days'] = $days;

        return $validated;
    }
}

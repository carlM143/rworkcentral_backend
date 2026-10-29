<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkSchedule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScheduleController extends Controller
{
    // GET ALL WORK SCHEDULES
    public function index(Request $request)
    {
        $query = WorkSchedule::with('scheduleType:id,type_name')->withCount('employments');

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

    // CREATE WORK SCHEDULE
    public function store(Request $request)
    {
        $validated = $this->validateSchedule($request);

        $schedule = WorkSchedule::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Work schedule created successfully.',
            'data' => $schedule->load('scheduleType'),
        ], 201);
    }

    // SHOW WORK SCHEDULE
    public function show(WorkSchedule $workSchedule)
    {
        return response()->json([
            'success' => true,
            'message' => 'Work schedule retrieved successfully.',
            'data' => $workSchedule->load('scheduleType')->loadCount('employments'),
        ]);
    }

    // UPDATE WORK SCHEDULE
    public function update(Request $request, WorkSchedule $workSchedule)
    {
        $validated = $this->validateSchedule($request, $workSchedule);

        $workSchedule->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Work schedule updated successfully.',
            'data' => $workSchedule->fresh()->load('scheduleType'),
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

    // VALIDATION
    private function validateSchedule(Request $request, ?WorkSchedule $workSchedule = null): array
    {
        return $request->validate([
            'schedule_type_id' => ['required', 'integer', Rule::exists('schedule_types', 'id')],
            'schedule_code' => ['required', 'string', 'max:50', Rule::unique('work_schedules', 'schedule_code')->ignore($workSchedule?->id)],
            'schedule_name' => ['required', 'string', 'max:255'],
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i', 'different:time_in'],
            'required_work_hours' => ['required', 'numeric', 'gt:0', 'lte:24'],
            'grace_period' => ['required', 'integer', 'between:0,120'],
            'break_start' => ['nullable', 'required_with:break_end', 'date_format:H:i'],
            'break_end' => ['nullable', 'required_with:break_start', 'date_format:H:i', 'different:break_start'],
            'status' => ['required', 'boolean'],
        ]);
    }
    
}
<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreOvertimeRequest;
use App\Models\OvertimeRequest;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OvertimeRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        $query = $employee->overtimeRequests()->with(['workSchedule', 'timeLog']);

        if ($request->filled('status')) {
            $query->where('status', strtolower($request->string('status')->trim()->toString()));
        }

        $records = $query->latest('id')->get()->map(fn ($r) => $this->formatOvertimeRequest($r));

        return response()->json([
            'success' => true,
            'message' => 'Overtime requests retrieved successfully.',
            'data' => $records,
        ]);
    }

    public function store(StoreOvertimeRequest $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        $validated = $request->validated();
        $scheduleId = $validated['work_schedule_id'] ?? null;

        if (! $scheduleId) {
            $scheduleId = $employee->employments()->latest('id')->first()?->work_schedule_id
                ?? WorkSchedule::first()?->id;
        }

        if (! $scheduleId) {
            return response()->json([
                'success' => false,
                'message' => 'No work schedule assigned or found.',
            ], 422);
        }

        $otDate = $validated['ot_date'] ?? $validated['start'] ?? now()->toDateString();

        if (! empty($validated['start_at']) && ! empty($validated['end_at'])) {
            $startAt = Carbon::parse($validated['start_at']);
            $endAt = Carbon::parse($validated['end_at']);
        } else {
            $startTime = $validated['start_time'] ?? '17:00';
            $endTime = $validated['end_time'] ?? '19:00';
            $startAt = Carbon::parse("$otDate $startTime");
            $endAt = Carbon::parse("$otDate $endTime");
            if (! empty($validated['ends_next_day']) || $endAt->lt($startAt)) {
                $endAt->addDay();
            }
        }

        $filePath = $this->handleFileUpload($request, $employee->id) ?? ($validated['file_path'] ?? null);
        $requestNo = 'OT-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        $otRequest = $employee->overtimeRequests()->create([
            'work_schedule_id' => $scheduleId,
            'request_no' => $requestNo,
            'ot_date' => $otDate,
            'start_at' => $startAt->toDateTimeString(),
            'end_at' => $endAt->toDateTimeString(),
            'reason' => $validated['reason'] ?? null,
            'file_path' => $filePath,
            'status' => 'pending',
        ]);

        $formatted = $this->formatOvertimeRequest($otRequest->load(['workSchedule', 'timeLog']));

        return response()->json([
            'success' => true,
            'message' => 'Overtime request submitted successfully.',
            'data' => $formatted,
        ], 201);
    }

    public function update(OvertimeRequest $overtimeRequest, StoreOvertimeRequest $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee || $overtimeRequest->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        if ($overtimeRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending overtime requests can be updated.',
            ], 422);
        }

        $validated = $request->validated();
        $otDate = $validated['ot_date'] ?? $validated['start'] ?? $overtimeRequest->ot_date;
        $overtimeRequest->ot_date = $otDate;

        if (! empty($validated['start_at']) && ! empty($validated['end_at'])) {
            $overtimeRequest->start_at = Carbon::parse($validated['start_at'])->toDateTimeString();
            $overtimeRequest->end_at = Carbon::parse($validated['end_at'])->toDateTimeString();
        } elseif (! empty($validated['start_time']) && ! empty($validated['end_time'])) {
            $startAt = Carbon::parse("$otDate {$validated['start_time']}");
            $endAt = Carbon::parse("$otDate {$validated['end_time']}");
            if (! empty($validated['ends_next_day']) || $endAt->lt($startAt)) {
                $endAt->addDay();
            }
            $overtimeRequest->start_at = $startAt->toDateTimeString();
            $overtimeRequest->end_at = $endAt->toDateTimeString();
        }

        if (array_key_exists('reason', $validated)) {
            $overtimeRequest->reason = $validated['reason'];
        }

        if ($request->boolean('remove_image') || ($request->has('image') && is_null($request->input('image')) && ! $request->hasFile('file') && ! $request->hasFile('image'))) {
            if ($overtimeRequest->file_path && Storage::disk('public')->exists($overtimeRequest->file_path)) {
                Storage::disk('public')->delete($overtimeRequest->file_path);
            }
            $overtimeRequest->file_path = null;
        } else {
            $filePath = $this->handleFileUpload($request, $employee->id);
            if ($filePath) {
                if ($overtimeRequest->file_path && $overtimeRequest->file_path !== $filePath && Storage::disk('public')->exists($overtimeRequest->file_path)) {
                    Storage::disk('public')->delete($overtimeRequest->file_path);
                }
                $overtimeRequest->file_path = $filePath;
            }
        }

        $overtimeRequest->save();

        return response()->json([
            'success' => true,
            'message' => 'Overtime request updated successfully.',
            'data' => $this->formatOvertimeRequest($overtimeRequest->fresh(['workSchedule', 'timeLog'])),
        ]);
    }

    public function cancel(OvertimeRequest $overtimeRequest, Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee || $overtimeRequest->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        if ($overtimeRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending overtime requests can be cancelled.',
            ], 422);
        }

        $overtimeRequest->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Overtime request cancelled successfully.',
            'data' => $this->formatOvertimeRequest($overtimeRequest->fresh(['workSchedule', 'timeLog'])),
        ]);
    }

    protected function formatOvertimeRequest(OvertimeRequest $ot): array
    {
        $startAt = $ot->start_at ? Carbon::parse($ot->start_at) : null;
        $endAt = $ot->end_at ? Carbon::parse($ot->end_at) : null;
        $otDate = $ot->ot_date
            ? (is_string($ot->ot_date) ? substr($ot->ot_date, 0, 10) : $ot->ot_date->format('Y-m-d'))
            : ($startAt?->format('Y-m-d') ?? now()->toDateString());

        $fileUrl = $ot->file_path
            ? (str_starts_with($ot->file_path, 'http') ? $ot->file_path : asset('storage/' . ltrim($ot->file_path, '/')))
            : null;

        $minutes = ($startAt && $endAt) ? (int) abs($startAt->diffInMinutes($endAt)) : 0;
        $endsNextDay = ($startAt && $endAt) ? $endAt->toDateString() !== $startAt->toDateString() : false;

        return [
            'id' => $ot->request_no ?: ('OT-' . $ot->id),
            'raw_id' => $ot->id,
            'request_no' => $ot->request_no,
            'category' => 'Overtime',
            'type' => 'Overtime',
            'start' => $otDate,
            'end' => $endsNextDay && $endAt ? $endAt->format('Y-m-d') : $otDate,
            'reason' => $ot->reason ?? '',
            'status' => $ot->status,
            'status_label' => ucfirst($ot->status),
            'file_path' => $ot->file_path,
            'file_url' => $fileUrl,
            'image' => $fileUrl ? [
                'name' => basename($ot->file_path),
                'dataUrl' => $fileUrl,
            ] : null,
            'timing' => [
                'startTime' => $startAt ? $startAt->format('H:i') : '',
                'endTime' => $endAt ? $endAt->format('H:i') : '',
                'endsNextDay' => $endsNextDay,
                'requestedMinutes' => $minutes,
            ],
            'work_schedule' => $ot->workSchedule,
            'created_at' => $ot->created_at?->toIso8601String(),
        ];
    }

    protected function handleFileUpload(Request $request, int $employeeId): ?string
    {
        $file = $request->file('file') ?? $request->file('image');
        if ($file && $file->isValid()) {
            return $file->store("requests/{$employeeId}", 'public');
        }

        $imageData = $request->input('image.dataUrl')
            ?? (is_string($request->input('image')) ? $request->input('image') : null)
            ?? $request->input('file_base64');

        if ($imageData && is_string($imageData) && str_starts_with($imageData, 'data:image')) {
            $extension = 'jpg';
            if (preg_match('/^data:(image\/[a-zA-Z0-9\+\-]+);base64,/', $imageData, $matches)) {
                $mimeType = $matches[1];
                $extension = match ($mimeType) {
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                    default => 'jpg',
                };
                $imageData = substr($imageData, strpos($imageData, ',') + 1);
            }

            $binary = base64_decode($imageData, true);
            if ($binary !== false) {
                $filename = 'req_' . Str::random(16) . '.' . $extension;
                $path = "requests/{$employeeId}/{$filename}";
                Storage::disk('public')->put($path, $binary);
                return $path;
            }
        }

        return null;
    }
}

<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreUndertimeRequest;
use App\Models\UndertimeRequest;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UndertimeRequestController extends Controller
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

        $query = $employee->undertimeRequests()->with(['workSchedule', 'timeLog']);

        if ($request->filled('status')) {
            $query->where('status', strtolower($request->string('status')->trim()->toString()));
        }

        $records = $query->latest('id')->get()->map(fn ($r) => $this->formatUndertimeRequest($r));

        return response()->json([
            'success' => true,
            'message' => 'Undertime requests retrieved successfully.',
            'data' => $records,
        ]);
    }

    public function store(StoreUndertimeRequest $request): JsonResponse
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

        $undertimeDate = $validated['undertime_date'] ?? $validated['start'] ?? now()->toDateString();

        if (! empty($validated['requested_time_out'])) {
            $requestedTimeOut = Carbon::parse($validated['requested_time_out']);
        } else {
            $reqTime = $validated['requested_time'] ?? '15:00';
            $requestedTimeOut = Carbon::parse("$undertimeDate $reqTime");
        }

        if (! empty($validated['scheduled_time_out'])) {
            $scheduledTimeOut = Carbon::parse($validated['scheduled_time_out']);
        } else {
            $schedTime = $validated['scheduled_time'] ?? '17:00';
            $scheduledTimeOut = Carbon::parse("$undertimeDate $schedTime");
        }

        $requestedMinutes = $validated['requested_minutes'] ?? null;
        if (! $requestedMinutes) {
            $requestedMinutes = (int) abs($scheduledTimeOut->diffInMinutes($requestedTimeOut));
        }

        $filePath = $this->handleFileUpload($request, $employee->id) ?? ($validated['file_path'] ?? null);
        $requestNo = 'UT-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        $utRequest = $employee->undertimeRequests()->create([
            'work_schedule_id' => $scheduleId,
            'request_no' => $requestNo,
            'undertime_date' => $undertimeDate,
            'requested_time_out' => $requestedTimeOut->toDateTimeString(),
            'scheduled_time_out' => $scheduledTimeOut->toDateTimeString(),
            'requested_minutes' => $requestedMinutes,
            'reason' => $validated['reason'],
            'file_path' => $filePath,
            'status' => 'pending',
        ]);

        $formatted = $this->formatUndertimeRequest($utRequest->load(['workSchedule', 'timeLog']));

        return response()->json([
            'success' => true,
            'message' => 'Undertime request submitted successfully.',
            'data' => $formatted,
        ], 201);
    }

    public function update(UndertimeRequest $undertimeRequest, StoreUndertimeRequest $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee || $undertimeRequest->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        if ($undertimeRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending undertime requests can be updated.',
            ], 422);
        }

        $validated = $request->validated();
        $undertimeDate = $validated['undertime_date'] ?? $validated['start'] ?? $undertimeRequest->undertime_date;
        $undertimeRequest->undertime_date = $undertimeDate;

        if (! empty($validated['requested_time_out'])) {
            $undertimeRequest->requested_time_out = Carbon::parse($validated['requested_time_out'])->toDateTimeString();
        } elseif (! empty($validated['requested_time'])) {
            $undertimeRequest->requested_time_out = Carbon::parse("$undertimeDate {$validated['requested_time']}")->toDateTimeString();
        }

        if (! empty($validated['scheduled_time_out'])) {
            $undertimeRequest->scheduled_time_out = Carbon::parse($validated['scheduled_time_out'])->toDateTimeString();
        } elseif (! empty($validated['scheduled_time'])) {
            $undertimeRequest->scheduled_time_out = Carbon::parse("$undertimeDate {$validated['scheduled_time']}")->toDateTimeString();
        }

        $reqOut = Carbon::parse($undertimeRequest->requested_time_out);
        $schedOut = Carbon::parse($undertimeRequest->scheduled_time_out);
        $undertimeRequest->requested_minutes = $validated['requested_minutes'] ?? (int) abs($schedOut->diffInMinutes($reqOut));

        if (array_key_exists('reason', $validated)) {
            $undertimeRequest->reason = $validated['reason'];
        }

        $filePath = $this->handleFileUpload($request, $employee->id);
        if ($filePath) {
            $undertimeRequest->file_path = $filePath;
        }

        $undertimeRequest->save();

        return response()->json([
            'success' => true,
            'message' => 'Undertime request updated successfully.',
            'data' => $this->formatUndertimeRequest($undertimeRequest->fresh(['workSchedule', 'timeLog'])),
        ]);
    }

    public function cancel(UndertimeRequest $undertimeRequest, Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee || $undertimeRequest->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        if ($undertimeRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending undertime requests can be cancelled.',
            ], 422);
        }

        $undertimeRequest->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Undertime request cancelled successfully.',
            'data' => $this->formatUndertimeRequest($undertimeRequest->fresh(['workSchedule', 'timeLog'])),
        ]);
    }

    protected function formatUndertimeRequest(UndertimeRequest $ut): array
    {
        $reqOut = $ut->requested_time_out ? Carbon::parse($ut->requested_time_out) : null;
        $schedOut = $ut->scheduled_time_out ? Carbon::parse($ut->scheduled_time_out) : null;
        $utDate = $ut->undertime_date
            ? (is_string($ut->undertime_date) ? substr($ut->undertime_date, 0, 10) : $ut->undertime_date->format('Y-m-d'))
            : ($reqOut?->format('Y-m-d') ?? now()->toDateString());

        $fileUrl = $ut->file_path
            ? (str_starts_with($ut->file_path, 'http') ? $ut->file_path : asset('storage/' . ltrim($ut->file_path, '/')))
            : null;

        return [
            'id' => $ut->request_no ?: ('UT-' . $ut->id),
            'raw_id' => $ut->id,
            'request_no' => $ut->request_no,
            'category' => 'Undertime',
            'type' => 'Undertime',
            'start' => $utDate,
            'end' => $utDate,
            'reason' => $ut->reason ?? '',
            'status' => $ut->status,
            'status_label' => ucfirst($ut->status),
            'file_path' => $ut->file_path,
            'file_url' => $fileUrl,
            'image' => $fileUrl ? [
                'name' => basename($ut->file_path),
                'dataUrl' => $fileUrl,
            ] : null,
            'timing' => [
                'startTime' => $reqOut ? $reqOut->format('H:i') : '',
                'endTime' => $schedOut ? $schedOut->format('H:i') : '',
                'scheduledTimeOut' => $schedOut ? $schedOut->format('H:i') : '',
                'requestedTimeOut' => $reqOut ? $reqOut->format('H:i') : '',
                'requestedMinutes' => (int) $ut->requested_minutes,
            ],
            'work_schedule' => $ut->workSchedule,
            'created_at' => $ut->created_at?->toIso8601String(),
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

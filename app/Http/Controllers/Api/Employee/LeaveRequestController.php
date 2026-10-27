<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreLeaveRequest;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LeaveRequestController extends Controller
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

        $query = $employee->leaveRequests()->with(['leaveType', 'leavePolicy', 'leaveDates']);

        if ($request->filled('status')) {
            $query->where('status', strtolower($request->string('status')->trim()->toString()));
        }

        $records = $query->latest('id')->get()->map(fn ($r) => $this->formatLeaveRequest($r));

        return response()->json([
            'success' => true,
            'message' => 'Leave requests retrieved successfully.',
            'data' => $records,
        ]);
    }

    public function types(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;
        $employmentType = $employee?->employments()->latest('id')->first()?->employmentType;

        $query = LeaveType::query()->where('status', 'active')->with(['leavePolicies' => function ($q) use ($employmentType) {
            if ($employmentType) {
                $q->where('employment_type_id', $employmentType->id);
            }
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Leave types retrieved successfully.',
            'data' => $query->get(),
        ]);
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        $validated = $request->validated();

        $leaveTypeId = $validated['leave_type_id'] ?? null;
        if (! $leaveTypeId && ! empty($validated['type'])) {
            $typeModel = LeaveType::where('leave_name', $validated['type'])
                ->orWhere('leave_code', $validated['type'])
                ->first();
            $leaveTypeId = $typeModel?->id;
        }

        if (! $leaveTypeId) {
            $firstType = LeaveType::where('status', 'active')->first();
            $leaveTypeId = $firstType?->id;
        }

        if (! $leaveTypeId) {
            return response()->json([
                'success' => false,
                'message' => 'No leave type found or configured in the system.',
            ], 422);
        }

        $policyId = $validated['leave_policy_id'] ?? null;
        if (! $policyId) {
            $employmentTypeId = $employee->employments()->latest('id')->first()?->employment_type_id;

            $policy = LeavePolicy::query()
                ->where('leave_type_id', $leaveTypeId)
                ->when($employmentTypeId, fn ($q) => $q->where('employment_type_id', $employmentTypeId))
                ->first()
                ?? LeavePolicy::where('leave_type_id', $leaveTypeId)->first()
                ?? LeavePolicy::first();

            $policyId = $policy?->id;
        }

        if (! $policyId) {
            return response()->json([
                'success' => false,
                'message' => 'No leave policy found for the selected leave type.',
            ], 422);
        }

        $requestDate = $validated['request_date'] ?? $validated['start_date'] ?? $validated['start'] ?? now()->toDateString();
        $startDate = $validated['start_date'] ?? $validated['start'] ?? $requestDate;
        $endDate = $validated['end_date'] ?? $validated['end'] ?? $startDate;

        $filePath = $this->handleFileUpload($request, $employee->id) ?? ($validated['file_path'] ?? null);
        $requestNo = 'LR-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        $leaveRequest = $employee->leaveRequests()->create([
            'leave_type_id' => $leaveTypeId,
            'leave_policy_id' => $policyId,
            'request_no' => $requestNo,
            'request_date' => $requestDate,
            'reason' => $validated['reason'] ?? null,
            'file_path' => $filePath,
            'status' => 'pending',
        ]);

        if (! empty($validated['dates']) && is_array($validated['dates'])) {
            foreach ($validated['dates'] as $date) {
                $rawDayType = is_array($date) ? ($date['day_type'] ?? 'whole_day') : 'whole_day';
                $dayType = in_array($rawDayType, ['whole_day', 'half_day']) ? $rawDayType : ($rawDayType === 'half' ? 'half_day' : 'whole_day');
                $leaveDate = is_array($date) ? ($date['leave_date'] ?? $date['date'] ?? $requestDate) : $date;
                $leaveRequest->leaveDates()->firstOrCreate(
                    ['leave_date' => $leaveDate],
                    [
                        'day_type' => $dayType,
                        'day_value' => is_array($date) ? ($date['day_value'] ?? ($dayType === 'half_day' ? 0.5 : 1.0)) : 1.0,
                    ]
                );
            }
        } else {
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            if ($end->lt($start)) {
                $end = $start->copy();
            }

            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $leaveRequest->leaveDates()->firstOrCreate(
                    ['leave_date' => $d->toDateString()],
                    [
                        'day_type' => 'whole_day',
                        'day_value' => 1.0,
                    ]
                );
            }
        }

        $formatted = $this->formatLeaveRequest($leaveRequest->load(['leaveType', 'leavePolicy', 'leaveDates']));

        return response()->json([
            'success' => true,
            'message' => 'Leave request submitted successfully.',
            'data' => $formatted,
        ], 201);
    }

    public function update(LeaveRequest $leaveRequest, StoreLeaveRequest $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee || $leaveRequest->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending leave requests can be updated.',
            ], 422);
        }

        $validated = $request->validated();

        if (! empty($validated['leave_type_id'])) {
            $leaveRequest->leave_type_id = $validated['leave_type_id'];
        } elseif (! empty($validated['type'])) {
            $typeModel = LeaveType::where('leave_name', $validated['type'])->orWhere('leave_code', $validated['type'])->first();
            if ($typeModel) {
                $leaveRequest->leave_type_id = $typeModel->id;
            }
        }

        if (array_key_exists('reason', $validated)) {
            $leaveRequest->reason = $validated['reason'];
        }

        $filePath = $this->handleFileUpload($request, $employee->id);
        if ($filePath) {
            $leaveRequest->file_path = $filePath;
        }

        $leaveRequest->save();

        $startDate = $validated['start_date'] ?? $validated['start'] ?? null;
        $endDate = $validated['end_date'] ?? $validated['end'] ?? $startDate;
        if ($startDate) {
            $leaveRequest->leaveDates()->delete();
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            if ($end->lt($start)) {
                $end = $start->copy();
            }
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $leaveRequest->leaveDates()->firstOrCreate(
                    ['leave_date' => $d->toDateString()],
                    [
                        'day_type' => 'whole_day',
                        'day_value' => 1.0,
                    ]
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Leave request updated successfully.',
            'data' => $this->formatLeaveRequest($leaveRequest->fresh(['leaveType', 'leavePolicy', 'leaveDates'])),
        ]);
    }

    public function cancel(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee || $leaveRequest->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending leave requests can be cancelled.',
            ], 422);
        }

        $leaveRequest->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Leave request cancelled successfully.',
            'data' => $this->formatLeaveRequest($leaveRequest->fresh(['leaveType', 'leavePolicy', 'leaveDates'])),
        ]);
    }

    protected function formatLeaveRequest(LeaveRequest $request): array
    {
        $dates = $request->leaveDates ? $request->leaveDates->sortBy('leave_date') : collect();
        $firstDateObj = $dates->first();
        $lastDateObj = $dates->last();

        $firstDate = $firstDateObj?->leave_date
            ? (is_string($firstDateObj->leave_date) ? substr($firstDateObj->leave_date, 0, 10) : $firstDateObj->leave_date->format('Y-m-d'))
            : (is_string($request->request_date) ? substr($request->request_date, 0, 10) : $request->request_date?->format('Y-m-d'));

        $lastDate = $lastDateObj?->leave_date
            ? (is_string($lastDateObj->leave_date) ? substr($lastDateObj->leave_date, 0, 10) : $lastDateObj->leave_date->format('Y-m-d'))
            : $firstDate;

        $fileUrl = $request->file_path
            ? (str_starts_with($request->file_path, 'http') ? $request->file_path : asset('storage/' . ltrim($request->file_path, '/')))
            : null;

        return [
            'id' => $request->request_no ?: ('LR-' . $request->id),
            'raw_id' => $request->id,
            'request_no' => $request->request_no,
            'category' => 'Leave',
            'type' => $request->leaveType?->leave_name ?? 'Vacation Leave',
            'leave_type_id' => $request->leave_type_id,
            'leave_policy_id' => $request->leave_policy_id,
            'start' => $firstDate ?: now()->toDateString(),
            'end' => $lastDate ?: ($firstDate ?: now()->toDateString()),
            'reason' => $request->reason ?? '',
            'status' => $request->status,
            'status_label' => ucfirst($request->status),
            'file_path' => $request->file_path,
            'file_url' => $fileUrl,
            'image' => $fileUrl ? [
                'name' => basename($request->file_path),
                'dataUrl' => $fileUrl,
            ] : null,
            'dates' => $dates->map(fn ($d) => [
                'id' => $d->id,
                'leave_date' => is_string($d->leave_date) ? substr($d->leave_date, 0, 10) : $d->leave_date?->format('Y-m-d'),
                'day_type' => $d->day_type,
                'day_value' => (float) $d->day_value,
            ])->values()->all(),
            'created_at' => $request->created_at?->toIso8601String(),
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

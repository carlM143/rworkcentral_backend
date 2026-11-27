<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ObaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ObaRequestcontroller extends Controller
{
    private const RELATIONS = [
        'obaEmployees',
        'images',
        'filer:id,firstname,lastname,department_id',
        'filer.department:id,department_name',
        'approver:id,name',
    ];

    // GET /admin/oba-requests?status=&department_id=&search=&date_from=&date_to=&per_page=
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $obaRequests = ObaRequest::with(self::RELATIONS)
            ->when(
                ! empty($validated['status']),
                fn ($query) => $query->where('status', $validated['status'])
            )
            ->when(
                ! empty($validated['department_id']),
                fn ($query) => $query->whereHas(
                    'filer',
                    fn ($q) => $q->where('department_id', $validated['department_id'])
                )
            )
            ->when(
                ! empty($validated['date_from']),
                fn ($query) => $query->whereDate('date_request', '>=', $validated['date_from'])
            )
            ->when(
                ! empty($validated['date_to']),
                fn ($query) => $query->whereDate('date_request', '<=', $validated['date_to'])
            )
            ->when(! empty($validated['search']), function ($query) use ($validated) {
                $term = '%'.trim($validated['search']).'%';

                $query->where(function ($q) use ($term) {
                    $q->where('destination', 'like', $term)
                        ->orWhere('reason', 'like', $term)
                        ->orWhereHas('filer', fn ($f) => $f
                            ->where('firstname', 'like', $term)
                            ->orWhere('lastname', 'like', $term))
                        ->orWhereHas('obaEmployees', fn ($o) => $o
                            ->where('employee_name', 'like', $term));
                });
            })
            ->orderByDesc('date_request')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (ObaRequest $r) => $this->present($r));

        return response()->json([
            'success' => true,
            'message' => 'OBA requests retrieved successfully.',
            'data' => $obaRequests,
            'summary' => $this->statusSummary(),
        ]);
    }

    // GET /admin/oba-requests/{obaRequest}
    public function show(ObaRequest $obaRequest): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'OBA request retrieved successfully.',
            'data' => $this->present($obaRequest->load(self::RELATIONS)),
        ]);
    }

    // PATCH /admin/oba-requests/{obaRequest}/approve
    // Final HR approval follows the department approval step.
    public function approve(Request $request, ObaRequest $obaRequest): JsonResponse
    {
        $this->authorizeHR($request);

        $result = $this->act($obaRequest, [ObaRequest::STATUS_DEPT_HEAD_APPROVED], [
            'status' => ObaRequest::STATUS_APPROVED,
            'approved_by' => $request->user()->id,
            'rejection_reason' => null,
        ], 'Department approval must be completed or overridden before HR approval.');

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'success' => true,
            'message' => 'OBA request approved successfully.',
            'data' => $this->present($result),
        ]);
    }

    // PATCH /admin/oba-requests/{obaRequest}/reject   body: { reason }
    // HR can reject while it is still pending or after the department head approved.
    public function reject(Request $request, ObaRequest $obaRequest): JsonResponse
    {
        $this->authorizeHR($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $result = $this->act($obaRequest, [
            ObaRequest::STATUS_PENDING,
            ObaRequest::STATUS_DEPT_HEAD_APPROVED,
        ], [
            'status' => ObaRequest::STATUS_REJECTED,
            'approved_by' => $request->user()->id, // HR user who acted
            'rejection_reason' => $validated['reason'],
        ], 'This OBA request can no longer be rejected.');

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'success' => true,
            'message' => 'OBA request rejected.',
            'data' => $this->present($result),
        ]);
    }

    /**
     * Locks the row, checks the status is still one of $allowed, then updates it.
     * The lock stops two HR users from approving/rejecting the same request at once.
     */
    private function act(ObaRequest $obaRequest, array $allowed, array $changes, string $error): ObaRequest|JsonResponse
    {
        return DB::transaction(function () use ($obaRequest, $allowed, $changes, $error) {
            $locked = ObaRequest::query()->lockForUpdate()->findOrFail($obaRequest->id);

            if (! in_array($locked->status, $allowed, true)) {
                return response()->json([
                    'success' => false,
                    'message' => $error,
                ], 422);
            }

            $locked->update($changes);

            return $locked->fresh(array_merge(self::RELATIONS, ['approver:id,name']));
        });
    }

    private function authorizeHR(Request $request): void
    {
        abort_unless(
            in_array($request->user()?->role?->name, ['Administrator', 'Human Resources'], true),
            403,
            'You are not authorized to approve or reject OBA requests.'
        );
    }

    /** Adds filer_name and department_name so the table needs no extra lookups. */
    private function present(ObaRequest $obaRequest): ObaRequest
    {
        $filer = $obaRequest->filer;

        $obaRequest->setAttribute(
            'filer_name',
            $filer ? trim($filer->firstname.' '.$filer->lastname) : null
        );
        $obaRequest->setAttribute('department_name', $filer?->department?->department_name);

        $approvals = \App\Support\DepartmentRequestApprovals::records('oba', $obaRequest->id)
            ->orderBy('id')
            ->get(['user_id', 'approver_name', 'approver_position', 'status', 'acted_at', 'remarks']);
        $obaRequest->setAttribute('department_approvals', $approvals);

        $deptStatus = 'skipped';
        if ($obaRequest->status === ObaRequest::STATUS_PENDING) {
            $deptStatus = $approvals->isNotEmpty() ? 'pending' : 'skipped';
        } elseif ($approvals->contains('status', 'overridden')) {
            $deptStatus = 'overridden';
        } elseif ($approvals->contains('status', 'rejected')) {
            $deptStatus = 'rejected';
        } elseif ($approvals->isNotEmpty()) {
            $deptStatus = 'approved';
        }
        $obaRequest->setAttribute('dept_head_status', $deptStatus);

        return $obaRequest;
    }

    /** Count of requests per status, for the stat cards (not affected by filters). */
    private function statusSummary(): array
    {
        return ObaRequest::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}

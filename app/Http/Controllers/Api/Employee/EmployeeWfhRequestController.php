<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Api\Admin\WfhRequestController as AdminWfhRequestController;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\WorkFromHome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeWfhRequestController extends Controller
{
    public function __construct(private readonly AdminWfhRequestController $requests) {}

    private function employee(Request $request): Employee
    {
        return $request->user()->employee ?? abort(404, 'No employee profile associated with this account.');
    }

    public function index(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $records = WorkFromHome::where('employee_id', $employee->id)->with('approvedBy:id,name')
            ->latest('id')->get()->map(fn ($record) => [
                'id' => $record->id,
                'request_no' => $record->request_no,
                'start_date' => $record->start_date?->toDateString(),
                'end_date' => $record->end_date?->toDateString(),
                'number_of_days' => $record->number_of_days,
                'reason' => $record->reason,
                'supervisor_name' => $record->supervisor_name,
                'request_date' => $record->request_date?->toDateString(),
                'status' => $record->status,
                'remarks' => $record->remarks,
                'approved_by' => $record->approvedBy?->name,
                'approved_at' => $record->approved_at?->toDateString(),
            ]);
        return response()->json(['success' => true, 'data' => $records]);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->merge(['employee_id' => $this->employee($request)->id]);
        return $this->requests->preview($request);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['employee_id' => $this->employee($request)->id, 'request_date' => now()->toDateString()]);
        return $this->requests->store($request);
    }
}

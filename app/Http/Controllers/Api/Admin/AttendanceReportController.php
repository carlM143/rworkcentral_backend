<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AttendanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceReportController extends Controller
{
    public function index(Request $request, AttendanceReportService $report)
    {
        abort_unless(in_array($request->user()?->role?->name, ['Administrator', 'Human Resources'], true), 403);
        $data = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'search' => ['nullable', 'string', 'max:150'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'employee_status' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'condition' => ['nullable', Rule::in(['absent', 'late', 'missing', 'no_logs'])],
            'details' => ['nullable', 'boolean'],
        ]);
        if (CarbonImmutable::parse($data['start'])->diffInDays(CarbonImmutable::parse($data['end'])) > 365) {
            throw ValidationException::withMessages(['end' => 'Choose a period of at most 366 days.']);
        }
        return response()->json($report->build($data));
    }
}

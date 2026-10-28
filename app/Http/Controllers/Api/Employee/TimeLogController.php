<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeLogController extends Controller
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

        $query = $employee->timeLogs();

        if ($request->filled('start_date')) {
            $query->whereDate('work_date', '>=', $request->date('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('work_date', '<=', $request->date('end_date'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Time logs retrieved successfully.',
            'data' => $query->latest('work_date')->get(),
        ]);
    }

    public function today(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        $todayLog = $employee->timeLogs()
            ->whereDate('work_date', Carbon::today())
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Today\'s time log retrieved successfully.',
            'data' => $todayLog,
        ]);
    }
}

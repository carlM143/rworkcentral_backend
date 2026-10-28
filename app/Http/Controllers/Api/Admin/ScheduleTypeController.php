<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScheduleType;

class ScheduleTypeController extends Controller
{
    public function index()
    {
        $scheduleTypes = ScheduleType::where('type_name', '!=', 'Flexible')->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'message' => 'Schedule types retrieved successfully.',
            'data' => $scheduleTypes,
        ]);
    }
}
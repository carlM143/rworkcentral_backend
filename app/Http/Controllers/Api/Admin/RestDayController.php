<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RestDay;

class RestDayController extends Controller
{
    public function index()
    {
        $restDays = RestDay::orderBy('id')->get();

        return response()->json([
            'success' => true,
            'message' => 'Rest days retrieved successfully.',
            'data' => $restDays,
        ]);
    }
}
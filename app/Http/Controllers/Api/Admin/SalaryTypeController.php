<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalaryType;
use Illuminate\Http\JsonResponse;

class SalaryTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $salaryTypes = SalaryType::query()
            ->select('id', 'type_name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Salary types retrieved successfully.',
            'data' => $salaryTypes,
        ]);
    }
}

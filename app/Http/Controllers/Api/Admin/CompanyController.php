<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Companies retrieved successfully.',
            'data' => Company::query()->orderBy('company_name')->get(['id', 'company_name', 'location', 'status']),
        ]);
    }
}

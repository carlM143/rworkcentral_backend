<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EmploymentType;

class EmploymentTypeController extends Controller
{
    public function index()
    {
        $employmentTypes = EmploymentType::orderBy('id')->get();

        return response()->json([
            'success' => true,
            'message' => 'Employment types retrieved successfully.',
            'data' => $employmentTypes,
        ]);
    }
}

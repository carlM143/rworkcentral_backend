<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmploymentDocument;

class EmploymentDocumentsController extends Controller
{
    public function index()
    {
        $documents = EmploymentDocument::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Employment documents retrieved successfully.',
            'data' => $documents,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    private function companyId(): int
    {
        return Company::query()->sole()->id;
    }

    private function checkCompany(Position $position): void
    {
        abort_unless(
            $position->department()->where('company_id', $this->companyId())->exists(),
            404,
            'Position not found.'
        );
    }

    public function index()
    {
        $positions = Position::query()
            ->whereHas('department', fn ($query) => $query->where('company_id', $this->companyId()))
            ->with('department:id,department_name,department_code')
            ->orderBy('position')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Positions retrieved successfully.',
            'data' => $positions,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'department_id' => [
                'required', 'integer',
                Rule::exists('departments', 'id')->where('company_id', $this->companyId()),
            ],
            'position' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'status' => ['required', 'boolean'],
        ]);

        $position = Position::create($data);
        $position->load('department:id,department_name,department_code');

        return response()->json([
            'success' => true,
            'message' => 'Position created successfully.',
            'data' => $position,
        ], 201);
    }

    public function show(Position $position)
    {
        $this->checkCompany($position);
        $position->load('department:id,department_name,department_code');

        return response()->json([
            'success' => true,
            'message' => 'Position retrieved successfully.',
            'data' => $position,
        ]);
    }

    public function update(Request $request, Position $position)
    {
        $this->checkCompany($position);

        $data = $request->validate([
            'department_id' => [
                'required', 'integer',
                Rule::exists('departments', 'id')->where('company_id', $this->companyId()),
            ],
            'position' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'status' => ['required', 'boolean'],
        ]);

        $position->update($data);
        $position->load('department:id,department_name,department_code');

        return response()->json([
            'success' => true,
            'message' => 'Position updated successfully.',
            'data' => $position,
        ]);
    }

    public function destroy(Position $position)
    {
        $this->checkCompany($position);
        $position->delete();

        return response()->json([
            'success' => true,
            'message' => 'Position deleted successfully.',
        ]);
    }
}
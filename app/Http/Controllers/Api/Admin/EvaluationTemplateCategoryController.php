<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EvaluationTemplate;
use App\Models\EvaluationTemplateCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EvaluationTemplateCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $counts = EvaluationTemplate::selectRaw('category, COUNT(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $categories = EvaluationTemplateCategory::orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'templatesCount' => (int) ($counts[$c->name] ?? 0),
        ]);

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:evaluation_template_categories,name'],
        ]);

        $category = EvaluationTemplateCategory::create([
            'name' => trim($data['name']),
        ]);

        return response()->json([
            'data' => [
                'id' => $category->id,
                'name' => $category->name,
                'templatesCount' => 0,
            ],
        ], 201);
    }

    public function update(Request $request, EvaluationTemplateCategory $category): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('evaluation_template_categories', 'name')->ignore($category->id),
            ],
        ]);

        $newName = trim($data['name']);
        $oldName = $category->name;

        DB::transaction(function () use ($category, $oldName, $newName) {
            $category->update(['name' => $newName]);
            if ($oldName !== $newName) {
                EvaluationTemplate::where('category', $oldName)->update(['category' => $newName]);
            }
        });

        $count = EvaluationTemplate::where('category', $newName)->count();

        return response()->json([
            'data' => [
                'id' => $category->id,
                'name' => $category->name,
                'templatesCount' => $count,
            ],
        ]);
    }

    public function destroy(EvaluationTemplateCategory $category): JsonResponse
    {
        $inUse = EvaluationTemplate::where('category', $category->name)->exists();
        if ($inUse) {
            return response()->json([
                'message' => 'This category is currently used by existing templates. Reassign or delete those templates first.',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }
}

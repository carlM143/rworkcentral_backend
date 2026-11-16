<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EvaluationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class EvaluationTemplateController extends Controller
{
    private function formatTemplate(EvaluationTemplate $t): array
    {
        $ext = strtoupper(pathinfo($t->original_name, PATHINFO_EXTENSION) ?: 'FILE');
        $sizeKb = round($t->size / 1024);
        $sizeLabel = $sizeKb >= 1024 ? round($sizeKb / 1024, 1).' MB' : $sizeKb.' KB';

        return [
            'id' => $t->id,
            'templateCode' => $t->template_code,
            'title' => $t->title,
            'category' => $t->category,
            'description' => $t->description ?? '',
            'originalName' => $t->original_name,
            'mimeType' => $t->mime_type,
            'fileExtension' => $ext,
            'size' => $t->size,
            'sizeLabel' => $sizeLabel,
            'status' => $t->status,
            'uploadedBy' => $t->user?->name ?? 'Administrator',
            'createdAt' => $t->created_at?->format('Y-m-d'),
            'updatedAt' => $t->updated_at?->format('Y-m-d'),
        ];
    }

    public function index(): JsonResponse
    {
        $templates = EvaluationTemplate::with('user')
            ->latest('id')
            ->get();

        $formatted = $templates->map(fn ($t) => $this->formatTemplate($t));

        $stats = [
            'total' => $templates->count(),
            'active' => $templates->where('status', true)->count(),
            'archived' => $templates->where('status', false)->count(),
            'categories' => $templates->pluck('category')->unique()->count(),
        ];

        return response()->json([
            'data' => $formatted,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'template_code' => ['nullable', 'string', 'max:60', 'unique:evaluation_templates,template_code'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'boolean'],
            'document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg', 'max:20480'],
        ]);

        $file = $data['document'];
        $path = $file->store('evaluation-templates', 'local');
        if ($path === false) {
            abort(500, 'Unable to store document template.');
        }

        $code = trim((string) ($data['template_code'] ?? ''));
        if ($code === '') {
            $code = 'TPL-'.strtoupper(Str::random(6));
        }

        try {
            $template = EvaluationTemplate::create([
                'user_id' => $request->user()?->id,
                'template_code' => $code,
                'title' => $data['title'],
                'category' => $data['category'],
                'description' => $data['description'] ?? null,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
                'status' => $request->has('status') ? $request->boolean('status') : true,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $this->formatTemplate($template->load('user'))], 201);
    }

    public function update(Request $request, EvaluationTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'template_code' => ['required', 'string', 'max:60', Rule::unique('evaluation_templates', 'template_code')->ignore($template->id)],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'boolean'],
            'document' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg', 'max:20480'],
        ]);

        $oldPath = $template->path;
        $newPath = null;

        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $newPath = $file->store('evaluation-templates', 'local');
            if ($newPath === false) {
                abort(500, 'Unable to store replacement document.');
            }
            $data['path'] = $newPath;
            $data['original_name'] = $file->getClientOriginalName();
            $data['mime_type'] = $file->getMimeType() ?: 'application/octet-stream';
            $data['size'] = $file->getSize();
        }

        unset($data['document']);
        if ($request->has('status')) {
            $data['status'] = $request->boolean('status');
        }

        try {
            $template->update($data);
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $exception;
        }

        if ($newPath && $oldPath && $oldPath !== $newPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return response()->json(['data' => $this->formatTemplate($template->load('user'))]);
    }

    public function download(EvaluationTemplate $template): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($template->path), 404, 'Document file not found.');

        return response()->download(
            Storage::disk('local')->path($template->path),
            $template->original_name,
            [
                'Content-Type' => $template->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    public function destroy(EvaluationTemplate $template): JsonResponse
    {
        $path = $template->path;
        $template->delete();

        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }

        return response()->json(['message' => 'Evaluation template deleted.']);
    }
}

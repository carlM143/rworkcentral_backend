<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LifecycleEvaluationDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class LifecycleEvaluationDocumentController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role?->name === 'Administrator', 403);
    }

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => LifecycleEvaluationDocument::where('employee_id', $employee->id)->latest()->get()]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $file = $data['document'];
        $path = $file->store('lifecycle-evaluations/'.$employee->id, 'local');
        if ($path === false) {
            abort(500, 'Unable to store evaluation document.');
        }

        try {
            $document = LifecycleEvaluationDocument::create([
                'employee_id' => $employee->id,
                'uploaded_by' => $request->user()->id,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $document], 201);
    }

    public function show(Request $request, Employee $employee, LifecycleEvaluationDocument $document): BinaryFileResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($document->employee_id === $employee->id, 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return response()->file(Storage::disk('local')->path($document->path), [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

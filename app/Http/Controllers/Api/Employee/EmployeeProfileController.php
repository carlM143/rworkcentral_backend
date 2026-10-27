<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\UpdateContactRequest;
use App\Http\Requests\Employee\UpdatePasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class EmployeeProfileController extends Controller
{
    private const PROFILE_RELATIONSHIPS = [
        'department',
        'user:id,name,email,role_id',
        'user.role:id,name',
        'contactInformation',
        'contactPersons',
        'governmentRequirements',
        'employments.employmentType',
        'employments.department',
        'employments.position',
        'employments.workSchedule.scheduleType',
        'employments.restday',
        'salarySetup.salaryType',
        'documents.employmentDocument',
    ];

    public function show(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        $data = $employee->load(self::PROFILE_RELATIONSHIPS)->toArray();

        $photoDataUrl = null;
        if ($employee->profile_photo && Storage::disk('local')->exists($employee->profile_photo)) {
            $bytes = Storage::disk('local')->get($employee->profile_photo);
            $mime = Storage::disk('local')->mimeType($employee->profile_photo) ?? 'image/jpeg';
            $photoDataUrl = 'data:'.$mime.';base64,'.base64_encode($bytes);
        }

        $data['profile_photo_url'] = $photoDataUrl ?? ($employee->profile_photo ? url('/api/employee/profile/photo') : null);
        $data['profile_photo_base64'] = $photoDataUrl;

        return response()->json([
            'success' => true,
            'message' => 'Employee profile retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function updateContact(UpdateContactRequest $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        $validated = $request->validated();

        $contactData = array_filter([
            'mobile' => $validated['mobile'] ?? null,
            'residential_address' => $validated['residential_address'] ?? null,
        ], fn ($val) => $val !== null);

        if (! empty($contactData)) {
            $employee->contactInformation()->updateOrCreate(
                ['employee_id' => $employee->id],
                array_merge(['email' => $request->user()->email], $contactData)
            );
        }

        if (! empty($validated['emergency_name'])) {
            $employee->contactPersons()->updateOrCreate(
                ['employee_id' => $employee->id],
                [
                    'name' => $validated['emergency_name'],
                    'relationship' => $validated['emergency_relationship'] ?? 'Contact',
                    'contact_number' => $validated['emergency_phone'] ?? '',
                    'address' => $validated['emergency_address'] ?? ($validated['residential_address'] ?? ''),
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Contact details updated successfully.',
            'data' => $employee->fresh(['contactInformation', 'contactPersons']),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The provided current password does not match our records.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        $photoFile = $request->file('photo') ?? $request->file('profile_photo') ?? $request->file('image');
        $photoBase64 = $request->input('photo_base64') ?? $request->input('photo') ?? $request->input('image');

        if (! $photoFile && ! $photoBase64) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide an image file or cropped base64 image.',
            ], 422);
        }

        $disk = Storage::disk('local');
        $directory = "employees/{$employee->id}/profile";

        // Remove existing photo if any
        if ($employee->profile_photo && $disk->exists($employee->profile_photo)) {
            $disk->delete($employee->profile_photo);
        }

        $storedPath = null;
        $binaryData = null;
        $mimeType = 'image/jpeg';

        if ($photoFile instanceof UploadedFile) {
            $request->validate([
                'photo' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
                'profile_photo' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
                'image' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);

            $storedPath = $photoFile->store($directory, 'local');
            $binaryData = $disk->get($storedPath);
            $mimeType = $disk->mimeType($storedPath) ?? 'image/jpeg';
        } elseif (is_string($photoBase64)) {
            // Parse base64 string
            $extension = 'jpg';
            if (preg_match('/^data:(image\/[a-zA-Z0-9\+\-]+);base64,/', $photoBase64, $matches)) {
                $mimeType = $matches[1];
                $extension = match ($mimeType) {
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };
                $photoBase64 = substr($photoBase64, strpos($photoBase64, ',') + 1);
            }

            $binaryData = base64_decode($photoBase64, true);

            if ($binaryData === false || strlen($binaryData) === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'The provided image data could not be decoded.',
                ], 422);
            }

            if (strlen($binaryData) > 5 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'The image size cannot exceed 5MB.',
                ], 422);
            }

            $fileName = 'photo_'.time().'.'.$extension;
            $storedPath = "{$directory}/{$fileName}";
            $disk->put($storedPath, $binaryData);
        }

        $employee->update(['profile_photo' => $storedPath]);

        $photoDataUrl = 'data:'.$mimeType.';base64,'.base64_encode($binaryData);

        return response()->json([
            'success' => true,
            'message' => 'Profile photo uploaded successfully (1:1 ratio square).',
            'data' => [
                'profile_photo' => $storedPath,
                'profile_photo_url' => $photoDataUrl,
                'profile_photo_base64' => $photoDataUrl,
            ],
        ]);
    }

    public function getPhoto(Request $request)
    {
        $employee = $request->user()?->employee;

        if (! $employee || ! $employee->profile_photo) {
            return response()->json(['message' => 'No profile photo found.'], 404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($employee->profile_photo)) {
            return response()->json(['message' => 'Photo file not found.'], 404);
        }

        $path = $disk->path($employee->profile_photo);
        $mime = $disk->mimeType($employee->profile_photo) ?? 'image/jpeg';

        return response()->file($path, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        if ($employee->profile_photo && Storage::disk('local')->exists($employee->profile_photo)) {
            Storage::disk('local')->delete($employee->profile_photo);
        }

        $employee->update(['profile_photo' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Profile photo removed successfully.',
            'data' => [
                'profile_photo' => null,
                'profile_photo_url' => null,
            ],
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class EmployeeService
{
    public function create(array $data): Employee
    {
        $storedFiles = [];

        try {
            return DB::transaction(function () use ($data, &$storedFiles) {
                $personal = $data['employee'];
                $contact = $data['contact'];
                $employment = $data['employment'];

                // Create User account automatically for the employee with default password '*' and 'Employee' role
                $employeeRole = \App\Models\Role::where('name', 'Employee')->first();
                $user = \App\Models\User::create([
                    'role_id' => $employeeRole?->id,
                    'name' => trim("{$personal['firstname']} {$personal['lastname']}"),
                    'email' => $contact['email'],
                    'password' => '*',
                ]);

                // Create Employee
                $employee = Employee::create([
                    'user_id' => $user->id,
                    'emp_id' => $this->generateEmployeeId(),
                    'department_id' => $employment['department_id'],
                    'firstname' => $personal['firstname'],
                    'lastname' => $personal['lastname'],
                    'middlename' => $personal['middlename'] ?? null,
                    'suffix' => $personal['suffix'] ?? null,
                    'birthday' => $personal['birthday'],
                    'age' => Carbon::parse($personal['birthday'])->age,
                    'gender' => $personal['gender'] ?? null,
                    'civil_status' => $personal['civil_status'] ?? null,
                    'status' => true,
                ]);

                // Upload Profile Photo
                $photo = $personal['profile_photo'] ?? null;

                if ($photo instanceof UploadedFile) {
                    $path = $photo->store("employees/{$employee->id}/profile", 'local');
                    $storedFiles[] = $path;

                    $employee->update(['profile_photo' => $path]);
                }

                // Create Contact Information
                $employee->contactInformation()->create([
                    'email' => $contact['email'],
                    'mobile' => $contact['mobile'] ?? null,
                    'residential_address' => $contact['residential_address'],
                ]);

                // Create Employment Information
                $employee->employments()->create([
                    'employment_type_id' => $employment['employment_type_id'],
                    'department_id' => $employment['department_id'],
                    'position_id' => $employment['position_id'],
                    'work_schedule_id' => $employment['work_schedule_id'] ?? null,
                    'restday_id' => $employment['restday_id'] ?? null,
                    'date_hired' => $employment['date_hired'],
                    'mobile' => $contact['mobile'] ?? null,
                    'residential_address' => $contact['residential_address'],
                ]);

                // Create Government Requirements
                foreach ($data['government_requirements'] ?? [] as $government) {
                    $employee->governmentRequirements()->create($government);
                }

                // Create Salary Setup (Optional)
                if (! empty($data['salary_setup'])) {
                    $employee->salarySetup()->create($data['salary_setup']);
                }

                // Create Emergency Contacts
                foreach ($data['contact_persons'] ?? [] as $person) {
                    $employee->contactPersons()->create($person);
                }

                // Upload Employee Documents
                foreach ($data['documents'] ?? [] as $document) {
                    if (! filter_var($document['submitted'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                        continue;
                    }

                    $path = null;

                    if (($document['file'] ?? null) instanceof UploadedFile) {
                        $path = $document['file']->store("employees/{$employee->id}/documents", 'local');
                        $storedFiles[] = $path;
                    }

                    $employee->documents()->create([
                        'employment_document_id' => $document['employment_document_id'],
                        'file_path' => $path,
                    ]);
                }

                return $employee;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedFiles);
            throw $exception;
        }
    }

    public function update(Employee $employee, array $data): Employee
    {
        $storedFiles = [];
        $filesToDelete = [];

        try {
            $employee = DB::transaction(function () use ($employee, $data, &$storedFiles, &$filesToDelete) {
                $personal = $data['employee'] ?? [];
                $photo = $personal['profile_photo'] ?? null;
                unset($personal['profile_photo']);

                // Recalculate age if birthday changes
                if (isset($personal['birthday'])) {
                    $personal['age'] = Carbon::parse($personal['birthday'])->age;
                }

                // Update Personal Information
                if ($personal !== []) {
                    $employee->update($personal);
                }

                // Update Profile Photo
                if ($photo instanceof UploadedFile) {
                    $path = $photo->store("employees/{$employee->id}/profile", 'local');
                    $storedFiles[] = $path;

                    if ($employee->profile_photo) {
                        $filesToDelete[] = $employee->profile_photo;
                    }

                    $employee->update(['profile_photo' => $path]);
                }

                // Update Contact Information
                if (isset($data['contact'])) {
                    $employee->contactInformation()->updateOrCreate(
                        [],
                        $data['contact']
                    );

                    $employmentContact = array_intersect_key(
                        $data['contact'],
                        array_flip(['mobile', 'residential_address'])
                    );

                    if ($employmentContact !== []) {
                        $employee->employments()
                            ->latest('id')
                            ->firstOrFail()
                            ->update($employmentContact);
                    }
                }

                // Update Employment Information
                if (isset($data['employment'])) {
                    $employment = $employee->employments()
                        ->latest('id')
                        ->firstOrFail();

                    $employment->update($data['employment']);

                    if (isset($data['employment']['department_id'])) {
                        $employee->update([
                            'department_id' => $data['employment']['department_id'],
                        ]);
                    }
                }

                // Create or Update Salary Setup (Optional)
                if (! empty($data['salary_setup'])) {
                    $employee->salarySetup()->updateOrCreate(
                        [],
                        $data['salary_setup']
                    );
                }

                // Synchronize Submitted Employment Documents
                if (array_key_exists('documents', $data)) {
                    $submittedDocuments = collect($data['documents'])
                        ->filter(fn (array $document) => filter_var(
                            $document['submitted'] ?? true,
                            FILTER_VALIDATE_BOOLEAN
                        ))
                        ->keyBy('employment_document_id');
                    $existingDocuments = $employee->documents()
                        ->get()
                        ->keyBy('employment_document_id');

                    foreach ($existingDocuments as $employmentDocumentId => $existingDocument) {
                        if ($submittedDocuments->has($employmentDocumentId)) {
                            continue;
                        }

                        if ($existingDocument->file_path) {
                            $filesToDelete[] = $existingDocument->file_path;
                        }

                        $existingDocument->delete();
                    }

                    foreach ($submittedDocuments as $employmentDocumentId => $document) {
                        $existingDocument = $existingDocuments->get($employmentDocumentId);
                        $values = [];

                        if (($document['file'] ?? null) instanceof UploadedFile) {
                            $path = $document['file']->store("employees/{$employee->id}/documents", 'local');
                            $storedFiles[] = $path;
                            $values['file_path'] = $path;

                            if ($existingDocument?->file_path) {
                                $filesToDelete[] = $existingDocument->file_path;
                            }
                        }

                        $employee->documents()->updateOrCreate(
                            ['employment_document_id' => $employmentDocumentId],
                            $values
                        );
                    }
                }

                return $employee->fresh();
            });

            // Delete old profile photo after successful transaction
            Storage::disk('local')->delete($filesToDelete);

            return $employee;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedFiles);
            throw $exception;
        }
    }

    private function generateEmployeeId(): int
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $employeeId = random_int(10000000, 99999999);

            if (! Employee::where('emp_id', $employeeId)->exists()) {
                return $employeeId;
            }
        }

        throw new RuntimeException('Unable to generate a unique employee number.');
    }
}

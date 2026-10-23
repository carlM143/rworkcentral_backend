<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EmployeeService
{
    public function create(array $data): Employee
    {
        $storedFiles = [];

        $roleId = Role::where('name', 'Employee')->value('id');

        if (! $roleId) {
            throw ValidationException::withMessages([
                'employee.role' => 'The default Employee role is not configured.',
            ]);
        }

        try {
            return DB::transaction(function () use ($data, $roleId, &$storedFiles) {
                $personal = $data['employee'];
                $contact = $data['contact'];
                $employment = $data['employment'];

                // Create Employee
                $employee = Employee::create([
                    'emp_id' => $this->generateEmployeeId(),
                    'department_id' => $employment['department_id'],
                    'role_id' => $roleId,
                    'firstname' => $personal['firstname'],
                    'lastname' => $personal['lastname'],
                    'middlename' => $personal['middlename'] ?? null,
                    'suffix' => $personal['suffix'] ?? null,
                    'birthday' => $personal['birthday'],
                    'age' => Carbon::parse($personal['birthday'])->age,
                    'gender' => $personal['gender'],
                    'civil_status' => $personal['civil_status'],
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
                    'mobile' => $contact['mobile'],
                    'residential_address' => $contact['residential_address'] ?? null,
                ]);

                // Create Employment Information
                $employee->employments()->create([
                    'employment_type_id' => $employment['employment_type_id'],
                    'department_id' => $employment['department_id'],
                    'position_id' => $employment['position_id'],
                    'work_schedule_id' => $employment['work_schedule_id'],
                    'restday_id' => $employment['restday_id'],
                    'date_hired' => $employment['date_hired'],
                    'mobile' => $contact['mobile'],
                    'residential_address' => $contact['residential_address'] ?? null,
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
                    $file = $document['file'];
                    $path = $file->store("employees/{$employee->id}/documents", 'local');
                    $storedFiles[] = $path;

                    $employee->documents()->create([
                        'doc_name' => $document['doc_name'],
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

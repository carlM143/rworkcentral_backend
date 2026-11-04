<?php

namespace Database\Seeders;

use App\Models\ContactPerson;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Employment;
use App\Models\EmploymentType;
use App\Models\Position;
use App\Models\WorkSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $defaultSchedule = WorkSchedule::where('schedule_code', 'WS-001')->first();

        $employees = [
            [
                'emp_id' => 1001,
                'firstname' => 'Roger',
                'middlename' => null,
                'lastname' => 'Perez',
                'suffix' => 'Jr.',
                'birthday' => '2003-01-28',
                'gender' => 'Male',
                'civil_status' => 'Single',
                'status' => true,

                'employment_type' => 'Regular',
                'department_code' => 'IT',
                'position' => 'Web Developer',
                'date_hired' => '2026-10-29',
                'mobile' => '09171234567',
                'residential_address' => 'El Reo, Butuan City',

                'contact_person' => [
                    'name' => 'Marian Perez',
                    'relationship' => 'Sister',
                    'contact_number' => '09843784737',
                    'address' => 'Florida, Butuan City',
                ],
            ],
            [
                'emp_id' => 1002,
                'firstname' => 'Maria',
                'middlename' => 'Lopez',
                'lastname' => 'Santos',
                'suffix' => null,
                'birthday' => '1998-06-14',
                'gender' => 'Female',
                'civil_status' => 'Single',
                'status' => true,

                'employment_type' => 'Regular',
                'department_code' => 'HR',
                'position' => 'HR Officer',
                'date_hired' => '2025-03-10',
                'mobile' => '09181234567',
                'residential_address' => 'Ampayon, Butuan City',

                'contact_person' => [
                    'name' => 'Jose Santos',
                    'relationship' => 'Father',
                    'contact_number' => '09192345678',
                    'address' => 'Ampayon, Butuan City',
                ],
            ],
            [
                'emp_id' => 1003,
                'firstname' => 'Carlo',
                'middlename' => 'Reyes',
                'lastname' => 'Mendoza',
                'suffix' => null,
                'birthday' => '1995-11-08',
                'gender' => 'Male',
                'civil_status' => 'Married',
                'status' => true,

                'employment_type' => 'Regular',
                'department_code' => 'FIN',
                'position' => 'Accountant',
                'date_hired' => '2024-07-15',
                'mobile' => '09173456789',
                'residential_address' => 'Libertad, Butuan City',

                'contact_person' => [
                    'name' => 'Ana Mendoza',
                    'relationship' => 'Wife',
                    'contact_number' => '09201234567',
                    'address' => 'Libertad, Butuan City',
                ],
            ],
            [
                'emp_id' => 1004,
                'firstname' => 'Angela',
                'middlename' => 'Mae',
                'lastname' => 'Flores',
                'suffix' => null,
                'birthday' => '1997-09-21',
                'gender' => 'Female',
                'civil_status' => 'Single',
                'status' => true,

                'employment_type' => 'Regular',
                'department_code' => 'OPS',
                'position' => 'Operations Coordinator',
                'date_hired' => '2023-11-20',
                'mobile' => '09175678901',
                'residential_address' => 'Bancasi, Butuan City',

                'contact_person' => [
                    'name' => 'Lorna Flores',
                    'relationship' => 'Mother',
                    'contact_number' => '09351234567',
                    'address' => 'Bancasi, Butuan City',
                ],
            ],
        ];

        foreach ($employees as $item) {
            $department = Department::where('department_code', $item['department_code'])->firstOrFail();

            $position = Position::where('department_id', $department->id)
                ->where('position', $item['position'])
                ->firstOrFail();

            $employmentType = EmploymentType::where('name', $item['employment_type'])->firstOrFail();

            $employee = Employee::updateOrCreate(
                [
                    'emp_id' => $item['emp_id'],
                ],
                [
                    'user_id' => null,
                    'department_id' => $department->id,
                    'firstname' => $item['firstname'],
                    'lastname' => $item['lastname'],
                    'middlename' => $item['middlename'],
                    'suffix' => $item['suffix'],
                    'profile_photo' => null,
                    'age' => Carbon::parse($item['birthday'])->age,
                    'gender' => $item['gender'],
                    'birthday' => $item['birthday'],
                    'civil_status' => $item['civil_status'],
                    'status' => $item['status'],
                ]
            );

            Employment::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                ],
                [
                    'employment_type_id' => $employmentType->id,
                    'department_id' => $department->id,
                    'position_id' => $position->id,
                    'work_schedule_id' => $defaultSchedule?->id,
                    'date_hired' => $item['date_hired'],
                    'mobile' => $item['mobile'],
                    'residential_address' => $item['residential_address'],
                ]
            );

            ContactPerson::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                ],
                [
                    'name' => $item['contact_person']['name'],
                    'relationship' => $item['contact_person']['relationship'],
                    'contact_number' => $item['contact_person']['contact_number'],
                    'address' => $item['contact_person']['address'],
                ]
            );
        }
    }
}

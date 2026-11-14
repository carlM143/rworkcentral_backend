<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ContactInformation;
use App\Models\ContactPerson;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Employment;
use App\Models\EmploymentType;
use App\Models\Location;
use App\Models\Position;
use App\Models\Role;
use App\Models\SalarySetup;
use App\Models\SalaryType;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EmployeeSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'password123';
    private const EMAIL_DOMAIN = 'rworkcentral.test';
    private const EMPLOYEE_ROLE = 'Employee';

    private const LOCATIONS = [
        'Davao' => ['city' => 'Davao City', 'province' => 'Davao del Sur'],
        'Manila' => ['city' => 'Manila', 'province' => 'Metro Manila'],
    ];

    public function run(): void
    {
        $company = Company::where('company_name', 'R Work Central')->firstOrFail();
        $defaultSchedule = WorkSchedule::where('schedule_code', 'WS-001')->first();
        $employeeRoleId = $this->employeeRoleId();
        $salaryTypes = SalaryType::pluck('id', 'type_name');

        $locations = collect(self::LOCATIONS)
            ->mapWithKeys(fn ($details, $name) => [$name => $this->ensureLocation($company, $name, $details)]);

        foreach ($this->employees() as $item) {
            DB::transaction(function () use ($item, $company, $defaultSchedule, $employeeRoleId, $locations, $salaryTypes) {
                $department = Department::where('company_id', $company->id)
                    ->where('department_code', $item['department_code'])
                    ->firstOrFail();

                $position = Position::where('department_id', $department->id)
                    ->where('position', $item['position'])
                    ->firstOrFail();

                $employmentType = EmploymentType::where('name', $item['employment_type'])->firstOrFail();

                $fullName = collect([
                    $item['firstname'],
                    $item['middlename'],
                    $item['lastname'],
                    $item['suffix'],
                ])->filter()->implode(' ');

                $email = $this->email($item);

                $user = User::updateOrCreate(
                    ['email' => $email],
                    [
                        'role_id' => $employeeRoleId,
                        'name' => $fullName,
                        'password' => self::DEFAULT_PASSWORD,
                    ]
                );

                $employee = Employee::updateOrCreate(
                    ['emp_id' => $item['emp_id']],
                    [
                        'user_id' => $user->id,
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
                        'status' => true,
                    ]
                );

                Employment::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'employment_type_id' => $employmentType->id,
                        'department_id' => $department->id,
                        'location_id' => $locations[$item['location']]->id,
                        'position_id' => $position->id,
                        'work_schedule_id' => $defaultSchedule?->id,
                        'date_hired' => now()->subMonths($item['hired_months_ago'])->toDateString(),
                        'mobile' => $item['mobile'],
                        'residential_address' => $item['residential_address'],
                    ]
                );

                ContactInformation::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'email' => $email,
                        'mobile' => $item['mobile'],
                        'residential_address' => $item['residential_address'],
                    ]
                );

                ContactPerson::updateOrCreate(
                    ['employee_id' => $employee->id],
                    $item['contact_person']
                );

                $salary = $item['salary'];

                SalarySetup::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'salary_type_id' => $salaryTypes[$salary['type']] ?? null,
                        'basic_salary' => $salary['basic_salary'],
                        'allowance' => $salary['allowance'] ?? null,
                        'bank_name' => $salary['bank_name'],
                        'account_name' => $salary['bank_name'] ? $fullName : null,
                        'account_number' => $salary['account_number'],
                    ]
                );
            });
        }

        $this->call(EmployeeTimeLogCorrectionSeeder::class);
    }

    private function email(array $item): string
    {
        $local = Str::of("{$item['firstname']}.{$item['lastname']}")
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9.]/', '');

        return "{$local}@" . self::EMAIL_DOMAIN;
    }

    private function employeeRoleId(): ?int
    {
        $column = collect(['name', 'role_name'])
            ->first(fn ($c) => Schema::hasColumn((new Role())->getTable(), $c));

        if (!$column) {
            return null;
        }

        return Role::where($column, self::EMPLOYEE_ROLE)->value('id');
    }

    private function ensureLocation(Company $company, string $name, array $details): Location
    {
        $hasCompany = Schema::hasColumn((new Location())->getTable(), 'company_id');

        $query = Location::where('location_name', $name);

        if ($hasCompany) {
            $query->where('company_id', $company->id);
        }

        $location = $query->first() ?? new Location(['location_name' => $name]);

        $location->fill([
            'city' => $details['city'],
            'province' => $details['province'],
            'status' => true,
        ]);

        if ($hasCompany) {
            $location->forceFill(['company_id' => $company->id]);
        }

        $location->save();

        return $location;
    }

    private function employees(): array
    {
        return [
            // DAVAO — R Web Solutions (2)
            [
                'emp_id' => 1001,
                'firstname' => 'Roger',
                'middlename' => null,
                'lastname' => 'Perez',
                'suffix' => 'Jr.',
                'birthday' => '2003-01-28',
                'gender' => 'Male',
                'civil_status' => 'Single',
                'employment_type' => 'Probationary',
                'department_code' => 'RWS',
                'position' => 'Web Developer',
                'location' => 'Davao',
                'hired_months_ago' => 1,
                'mobile' => '09171234567',
                'residential_address' => 'Buhangin, Davao City',
                'salary' => [
                    'type' => 'Monthly',
                    'basic_salary' => 22000,
                    'bank_name' => 'BDO',
                    'account_number' => '0012345678901',
                ],
                'contact_person' => [
                    'name' => 'Marian Perez',
                    'relationship' => 'Sister',
                    'contact_number' => '09843784737',
                    'address' => 'Buhangin, Davao City',
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
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Project Coordinator',
                'location' => 'Davao',
                'hired_months_ago' => 20,
                'mobile' => '09181234567',
                'residential_address' => 'Matina, Davao City',
                'salary' => [
                    'type' => 'Monthly',
                    'basic_salary' => 28000,
                    'allowance' => 500,
                    'bank_name' => 'BPI',
                    'account_number' => '3456789012',
                ],
                'contact_person' => [
                    'name' => 'Jose Santos',
                    'relationship' => 'Father',
                    'contact_number' => '09192345678',
                    'address' => 'Matina, Davao City',
                ],
            ],

            // DAVAO — Airpro (3)
            [
                'emp_id' => 1003,
                'firstname' => 'Carlo',
                'middlename' => 'Reyes',
                'lastname' => 'Mendoza',
                'suffix' => null,
                'birthday' => '1995-11-08',
                'gender' => 'Male',
                'civil_status' => 'Married',
                'employment_type' => 'Regular',
                'department_code' => 'AR',
                'position' => 'Lead Technician',
                'location' => 'Davao',
                'hired_months_ago' => 28,
                'mobile' => '09173456789',
                'residential_address' => 'Toril, Davao City',
                'salary' => [
                    'type' => 'Monthly',
                    'basic_salary' => 30000,
                    'bank_name' => 'Metrobank',
                    'account_number' => '1234567890123',
                ],
                'contact_person' => [
                    'name' => 'Ana Mendoza',
                    'relationship' => 'Wife',
                    'contact_number' => '09201234567',
                    'address' => 'Toril, Davao City',
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
                'employment_type' => 'Regular',
                'department_code' => 'AR',
                'position' => 'Field Technician',
                'location' => 'Davao',
                'hired_months_ago' => 36,
                'mobile' => '09175678901',
                'residential_address' => 'Lanang, Davao City',
                'salary' => [
                    'type' => 'Daily',
                    'basic_salary' => 750,
                    'bank_name' => 'Landbank',
                    'account_number' => '9876543210',
                ],
                'contact_person' => [
                    'name' => 'Lorna Flores',
                    'relationship' => 'Mother',
                    'contact_number' => '09351234567',
                    'address' => 'Lanang, Davao City',
                ],
            ],
            [
                'emp_id' => 1005,
                'firstname' => 'Mark',
                'middlename' => 'Dizon',
                'lastname' => 'Villanueva',
                'suffix' => null,
                'birthday' => '2001-04-03',
                'gender' => 'Male',
                'civil_status' => 'Single',
                'employment_type' => 'Probationary',
                'department_code' => 'AR',
                'position' => 'Junior Technician',
                'location' => 'Davao',
                'hired_months_ago' => 3,
                'mobile' => '09176789012',
                'residential_address' => 'Bajada, Davao City',
                'salary' => [
                    'type' => 'Daily',
                    'basic_salary' => 610,
                    'bank_name' => null,
                    'account_number' => null,
                ],
                'contact_person' => [
                    'name' => 'Rosa Villanueva',
                    'relationship' => 'Mother',
                    'contact_number' => '09361234567',
                    'address' => 'Bajada, Davao City',
                ],
            ],

            // MANILA — R Web Solutions (3)
            [
                'emp_id' => 1006,
                'firstname' => 'Kristine',
                'middlename' => 'Gomez',
                'lastname' => 'Bautista',
                'suffix' => null,
                'birthday' => '1996-02-17',
                'gender' => 'Female',
                'civil_status' => 'Married',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'UI/UX Designer',
                'location' => 'Manila',
                'hired_months_ago' => 14,
                'mobile' => '09177890123',
                'residential_address' => 'Sampaloc, Manila',
                'salary' => [
                    'type' => 'Monthly',
                    'basic_salary' => 35000,
                    'bank_name' => 'BDO',
                    'account_number' => '0098765432109',
                ],
                'contact_person' => [
                    'name' => 'Paolo Bautista',
                    'relationship' => 'Husband',
                    'contact_number' => '09371234567',
                    'address' => 'Sampaloc, Manila',
                ],
            ],
            [
                'emp_id' => 1007,
                'firstname' => 'Joshua',
                'middlename' => 'Tan',
                'lastname' => 'Ramos',
                'suffix' => null,
                'birthday' => '2000-12-05',
                'gender' => 'Male',
                'civil_status' => 'Single',
                'employment_type' => 'Probationary',
                'department_code' => 'RWS',
                'position' => 'Software Developer',
                'location' => 'Manila',
                'hired_months_ago' => 4,
                'mobile' => '09178901234',
                'residential_address' => 'Ermita, Manila',
                'salary' => [
                    'type' => 'Monthly',
                    'basic_salary' => 25000,
                    'bank_name' => 'UnionBank',
                    'account_number' => '109876543210',
                ],
                'contact_person' => [
                    'name' => 'Linda Ramos',
                    'relationship' => 'Mother',
                    'contact_number' => '09381234567',
                    'address' => 'Ermita, Manila',
                ],
            ],
            [
                'emp_id' => 1008,
                'firstname' => 'Patricia',
                'middlename' => 'Cruz',
                'lastname' => 'Garcia',
                'suffix' => null,
                'birthday' => '1990-08-29',
                'gender' => 'Female',
                'civil_status' => 'Married',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Web Development Manager',
                'location' => 'Manila',
                'hired_months_ago' => 50,
                'mobile' => '09179012345',
                'residential_address' => 'Malate, Manila',
                'salary' => [
                    'type' => 'Monthly',
                    'basic_salary' => 60000,
                    'allowance' => 500,
                    'bank_name' => 'BPI',
                    'account_number' => '8765432109',
                ],
                'contact_person' => [
                    'name' => 'Ramon Garcia',
                    'relationship' => 'Husband',
                    'contact_number' => '09391234567',
                    'address' => 'Malate, Manila',
                ],
            ],

            // MANILA — Airpro (2)
            [
                'emp_id' => 1009,
                'firstname' => 'Daniel',
                'middlename' => 'Santiago',
                'lastname' => 'Aquino',
                'suffix' => null,
                'birthday' => '1999-03-11',
                'gender' => 'Male',
                'civil_status' => 'Single',
                'employment_type' => 'Probationary',
                'department_code' => 'AR',
                'position' => 'IT Support Specialist',
                'location' => 'Manila',
                'hired_months_ago' => 2,
                'mobile' => '09170123456',
                'residential_address' => 'Tondo, Manila',
                'salary' => [
                    'type' => 'Monthly',
                    'basic_salary' => 24000,
                    'bank_name' => 'Security Bank',
                    'account_number' => '0000123456789',
                ],
                'contact_person' => [
                    'name' => 'Teresa Aquino',
                    'relationship' => 'Mother',
                    'contact_number' => '09401234567',
                    'address' => 'Tondo, Manila',
                ],
            ],
            [
                'emp_id' => 1010,
                'firstname' => 'Bea',
                'middlename' => 'Navarro',
                'lastname' => 'Castillo',
                'suffix' => null,
                'birthday' => '2002-07-24',
                'gender' => 'Female',
                'civil_status' => 'Single',
                'employment_type' => 'Probationary',
                'department_code' => 'AR',
                'position' => 'Field Technician',
                'location' => 'Manila',
                'hired_months_ago' => 5,
                'mobile' => '09171357924',
                'residential_address' => 'Paco, Manila',
                'salary' => [
                    'type' => 'Daily',
                    'basic_salary' => 695,
                    'bank_name' => 'Metrobank',
                    'account_number' => '3210987654321',
                ],
                'contact_person' => [
                    'name' => 'Edgar Castillo',
                    'relationship' => 'Father',
                    'contact_number' => '09411234567',
                    'address' => 'Paco, Manila',
                ],
            ],
                        // DRIVER — Airpro
            [
                'emp_id' => 1011,
                'firstname' => 'Ramon',
                'middlename' => 'Delos Reyes',
                'lastname' => 'Torres',
                'suffix' => null,
                'birthday' => '1988-05-19',
                'gender' => 'Male',
                'civil_status' => 'Married',
                'employment_type' => 'Regular',
                'department_code' => 'AR',
                'position' => 'Driver',
                'location' => 'Davao',
                'hired_months_ago' => 18,
                'mobile' => '09172468135',
                'residential_address' => 'Panacan, Davao City',
                'salary' => [
                    'type' => 'Daily',
                    'basic_salary' => 650,
                    'bank_name' => 'Landbank',
                    'account_number' => '5544332211',
                ],
                'contact_person' => [
                    'name' => 'Luz Torres',
                    'relationship' => 'Wife',
                    'contact_number' => '09421234567',
                    'address' => 'Panacan, Davao City',
                ],
            ],
        ];
    }
}
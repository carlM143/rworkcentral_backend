<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ContactInformation;
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

/**
 * RWEB employees, from the client's lists:
 *   - "Employee List - RWEB.xlsx"       birthday and contact number
 *   - "sample_compensation_template"    name, position, rank, department,
 *                                        location, status, date hired (updated)
 *
 * Salary and allowances are left empty: the client has not given the amounts
 * yet (fill them in Employees › Salary Rates, or update this seeder).
 * Rank and date of latest appointment are kept here for reference; the
 * employees table has no columns for them yet.
 */
class EmployeeSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'password123';
    private const EMAIL_DOMAIN = 'rworkcentral.test';
    private const EMPLOYEE_ROLE = 'Employee';

    /** Work locations from the RBDB list, with their minimum wage region. */
    private const LOCATIONS = [
        'DAVAO-HO' => ['city' => 'Davao City', 'province' => 'Davao del Sur', 'region_code' => 'XI'],
        'PASIG' => ['city' => 'Pasig City', 'province' => 'Metro Manila', 'region_code' => 'NCR'],
    ];

    public function run(): void
    {
        $company = Company::where('company_name', 'R Work Central')->firstOrFail();
        $defaultSchedule = WorkSchedule::where('schedule_code', 'WS-001')->first();
        $employeeRoleId = $this->employeeRoleId();
        $monthlyTypeId = SalaryType::where('type_name', 'Monthly')->value('id');

        $locations = collect(self::LOCATIONS)
            ->mapWithKeys(fn ($details, $name) => [$name => $this->ensureLocation($company, $name, $details)]);

        foreach ($this->employees() as $item) {
            DB::transaction(function () use ($item, $company, $defaultSchedule, $employeeRoleId, $locations, $monthlyTypeId) {
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
                        'gender' => null,       
                        'birthday' => $item['birthday'],
                        'civil_status' => null, 
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
                        'date_hired' => $item['date_hired'],
                        'mobile' => $item['mobile'],
                        'residential_address' => '', // not in the client's list
                    ]
                );

                ContactInformation::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'email' => $email,
                        'mobile' => $item['mobile'],
                        'residential_address' => '', // not in the client's list
                    ]
                );

                // Salary and allowances: amounts not given yet by the client.
                SalarySetup::firstOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'salary_type_id' => $monthlyTypeId,
                        'basic_salary' => null,
                        'allowance' => null,
                        'bank_name' => null,
                        'account_name' => null,
                        'account_number' => null,
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
        $table = (new Location())->getTable();
        $hasCompany = Schema::hasColumn($table, 'company_id');

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

        // Minimum wage region (Compensation › Regional Rates)
        if (Schema::hasColumn($table, 'region_code')) {
            $location->forceFill([
                'region_code' => $details['region_code'],
                'wage_classification' => 'non_agriculture',
            ]);
        }

        $location->save();

        return $location;
    }

    private function employees(): array
    {
        return [
            [
                'emp_id' => 1001,
                'firstname' => 'Roger',
                'middlename' => 'Candilasa',
                'lastname' => 'Perez',
                'suffix' => 'Jr.',
                'birthday' => '2003-01-28',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Web Developer',
                'rank' => 'Staff/Rank & File',
                'location' => 'DAVAO-HO',
                'date_hired' => '2026-04-25',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09202415379',
            ],
            [
                'emp_id' => 1002, // company ID 2
                'firstname' => 'Alyzza',
                'middlename' => 'Papa',
                'lastname' => 'Escalante',
                'suffix' => null,
                'birthday' => '1997-09-14',
                'employment_type' => 'Regular',
                'department_code' => 'RWEB',
                'position' => 'General Manager',
                'rank' => 'Executive',
                'location' => 'PASIG',
                'date_hired' => '2019-07-16',
                'date_of_latest_appointment' => '2026-09-26',
                'mobile' => '09151016998',
            ],
            [
                'emp_id' => 1003, // company ID 00-24
                'firstname' => 'Gil Dominic',
                'middlename' => 'Aro',
                'lastname' => 'Gone',
                'suffix' => null,
                'birthday' => '1995-03-31',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Asst. Graphic Designer Supervisor',
                'rank' => 'Supervisory',
                'location' => 'DAVAO-HO',
                'date_hired' => '2023-06-26',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09773157341',
            ],
            [
                'emp_id' => 1004, // company ID 00-28
                'firstname' => 'Queen Gianna Claire',
                'middlename' => 'Maybanting',
                'lastname' => 'Palmes',
                'suffix' => null,
                'birthday' => '2000-07-16',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Operations Supervisor',
                'rank' => 'Supervisory',
                'location' => 'DAVAO-HO',
                'date_hired' => '2023-11-15',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09070101032',
            ],
            [
                'emp_id' => 1005, // company ID 00-27
                'firstname' => 'Rose',
                'middlename' => 'Esteban',
                'lastname' => 'Rivera',
                'suffix' => null,
                'birthday' => '2001-02-01',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Operations Supervisor',
                'rank' => 'Supervisory',
                'location' => 'PASIG',
                'date_hired' => '2023-12-04',
                'date_of_latest_appointment' => '2026-09-26',
                'mobile' => '09706059087',
            ],
            [
                'emp_id' => 1006, // company ID 00-31
                'firstname' => 'Nicolle Angelie',
                'middlename' => 'Bacani',
                'lastname' => 'Gacayan',
                'suffix' => null,
                'birthday' => '2001-11-06',
                'employment_type' => 'Regular',
                'department_code' => 'OPR',
                'position' => 'UI/UX & System Designer',
                'rank' => 'Staff/Rank & File',
                'location' => 'DAVAO-HO',
                'date_hired' => '2024-06-10',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09773546170',
            ],
            [
                'emp_id' => 1007, // company ID 00-34
                'firstname' => 'Anthony',
                'middlename' => 'Moring',
                'lastname' => 'Algabre',
                'suffix' => null,
                'birthday' => '2001-10-10',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Senior Technical Lead',
                'rank' => 'Supervisory',
                'location' => 'DAVAO-HO',
                'date_hired' => '2024-06-10',
                'date_of_latest_appointment' => '2026-09-30',
                'mobile' => '09662457483',
            ],
            [
                'emp_id' => 1008, // company ID 00-39
                'firstname' => 'Mark Jhon Lloyd',
                'middlename' => 'Seda',
                'lastname' => 'Jandulong',
                'suffix' => null,
                'birthday' => '2001-08-30',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Content Writer',
                'rank' => 'Staff/Rank & File',
                'location' => 'PASIG',
                'date_hired' => '2025-02-03',
                'date_of_latest_appointment' => '2026-09-26',
                'mobile' => '09560957375',
            ],
            [
                'emp_id' => 1009, // company ID 00-41
                'firstname' => 'Maviel May',
                'middlename' => 'Haus',
                'lastname' => 'Segurog',
                'suffix' => null,
                'birthday' => '2002-05-26',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Asst. Sales Supervisor',
                'rank' => 'Staff/Rank & File',
                'location' => 'DAVAO-HO',
                'date_hired' => '2025-05-14',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09124824257',
            ],
            [
                'emp_id' => 1010, // company ID 00-43
                'firstname' => 'Kent Joemar',
                'middlename' => 'Luna',
                'lastname' => 'Escoto',
                'suffix' => null,
                'birthday' => '2003-10-10',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'UI/UX Designer',
                'rank' => 'Staff/Rank & File',
                'location' => 'DAVAO-HO',
                'date_hired' => '2025-10-07',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09507541450',
            ],
            [
                'emp_id' => 1011, // company ID 00-46
                'firstname' => 'Keefe Benedict',
                'middlename' => 'Batulan',
                'lastname' => 'Patosa',
                'suffix' => null,
                'birthday' => '2002-10-23',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Asst. Supervisor - Web Dev',
                'rank' => 'Supervisory',
                'location' => 'DAVAO-HO',
                'date_hired' => '2025-09-18',
                'date_of_latest_appointment' => '2026-09-30',
                'mobile' => '09453426009',
            ],
            [
                'emp_id' => 1012, // company ID 00-47
                'firstname' => 'Eric',
                'middlename' => 'Miguel',
                'lastname' => 'Ramones',
                'suffix' => 'Jr.',
                'birthday' => '2003-09-12',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Graphic Designer',
                'rank' => 'Staff/Rank & File',
                'location' => 'DAVAO-HO',
                'date_hired' => '2025-09-18',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09087337926',
            ],
            [
                'emp_id' => 1013, // company ID 00-48
                'firstname' => 'Keren Happuch',
                'middlename' => 'Cifra',
                'lastname' => 'Lanado',
                'suffix' => null,
                'birthday' => '2003-03-26',
                'employment_type' => 'Regular',
                'department_code' => 'FAD',
                'position' => 'Senior Accounting Staff',
                'rank' => 'Staff/Rank & File',
                'location' => 'DAVAO-HO',
                'date_hired' => '2025-09-29',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09772994005',
            ],
            [
                'emp_id' => 1014, // company ID 52
                'firstname' => 'John Carl',
                'middlename' => 'Dandan',
                'lastname' => 'Murillo',
                'suffix' => null,
                'birthday' => '1994-10-08',
                'employment_type' => 'Probationary',
                'department_code' => 'RWS',
                'position' => 'Systems Developer',
                'rank' => 'Staff/Rank & File',
                'location' => 'DAVAO-HO',
                'date_hired' => '2026-08-23',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09854586073',
            ],
            [
                'emp_id' => 1015, // company ID 00-053
                'firstname' => 'Ahadon',
                'middlename' => 'Buladaco',
                'lastname' => 'Caraing',
                'suffix' => 'Jr.',
                'birthday' => '2003-04-13',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Junior Technical Lead',
                'rank' => 'Supervisory',
                'location' => 'DAVAO-HO',
                'date_hired' => '2026-03-09',
                'date_of_latest_appointment' => '2026-09-30',
                'mobile' => '0967406638',
            ],
            [
                'emp_id' => 1016,
                'firstname' => 'John Ross',
                'middlename' => 'Saludares',
                'lastname' => 'Guillermo',
                'suffix' => null,
                'birthday' => '2002-09-04',
                'employment_type' => 'Regular',
                'department_code' => 'RWS',
                'position' => 'Graphic Designer',
                'rank' => 'Staff/Rank & File',
                'location' => 'DAVAO-HO',
                'date_hired' => '2026-01-06',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09663380831',
            ],
            [
                'emp_id' => 1017, // company ID 55
                'firstname' => 'Trisha',
                'middlename' => 'Delima',
                'lastname' => 'Rhom',
                'suffix' => null,
                'birthday' => '2002-09-20',
                'employment_type' => 'Probationary',
                'department_code' => 'RWS',
                'position' => 'Content Writer',
                'rank' => 'Staff/Rank & File',
                'location' => 'DAVAO-HO',
                'date_hired' => '2026-05-13',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09630705893',
            ],
            [
                'emp_id' => 1018, // company ID 00056
                'firstname' => 'Christian Paul',
                'middlename' => 'Tomenlaco',
                'lastname' => 'Manota',
                'suffix' => null,
                'birthday' => '2000-04-17',
                'employment_type' => 'Probationary',
                'department_code' => 'RWS',
                'position' => 'Project Manager',
                'rank' => 'Staff/Rank & File',
                'location' => 'DAVAO-HO',
                'date_hired' => '2026-08-03',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09913696376',
            ],
            [
                'emp_id' => 1019,
                'firstname' => 'Charles',
                'middlename' => 'Simblante',
                'lastname' => 'Evangelio',
                'suffix' => 'Jr.',
                'birthday' => '2004-02-07',
                'employment_type' => 'Probationary',
                'department_code' => 'RWS',
                'position' => 'Full Stack Developer',
                'rank' => 'Staff/Rank & File',
                'location' => 'DAVAO-HO',
                'date_hired' => '2026-09-07',
                'date_of_latest_appointment' => '2026-09-01',
                'mobile' => '09071774381',
            ],
        ];
    }
}
<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\SalarySetup;
use App\Models\SalaryType;
use Illuminate\Database\Seeder;

/**
 * Salary setup for the RWEB employees in EmployeeSeeder (run that one first).
 *
 * The basic salaries are PLACEHOLDERS (random, roughly scaled by position)
 * because the client has not given the amounts yet. Replace them once received.
 * Allowance and bank details are not set here.
 */
class SalarySetupSeeder extends Seeder
{
    /** Monthly basic salary per emp_id. */
    private const BASIC_SALARIES = [
        // DAVAO-HO and PASIG, ordered by emp_id
        1001 => 23500, // Perez       - Web Developer
        1002 => 85000, // Escalante   - General Manager (PASIG)
        1003 => 28500, // Gone        - Asst. Graphic Designer Supervisor
        1004 => 32000, // Palmes      - Operations Supervisor
        1005 => 36500, // Rivera      - Operations Supervisor (PASIG)
        1006 => 27000, // Gacayan     - UI/UX & System Designer
        1007 => 48000, // Algabre     - Senior Technical Lead
        1008 => 24000, // Jandulong   - Content Writer (PASIG)
        1009 => 25500, // Segurog     - Asst. Sales Supervisor
        1010 => 22500, // Escoto      - UI/UX Designer
        1011 => 34000, // Patosa      - Asst. Supervisor - Web Dev
        1012 => 19500, // Ramones     - Graphic Designer
        1013 => 26000, // Lanado      - Senior Accounting Staff
        1014 => 28000, // Murillo     - Systems Developer
        1015 => 35500, // Caraing     - Junior Technical Lead
        1016 => 18500, // Guillermo   - Graphic Designer
        1017 => 17500, // Rhom        - Content Writer
        1018 => 33000, // Manota      - Project Manager
        1019 => 24500, // Evangelio   - Full Stack Developer
    ];

    public function run(): void
    {
        $monthlyTypeId = SalaryType::where('type_name', 'Monthly')->firstOrFail()->id;

        foreach (self::BASIC_SALARIES as $empId => $basicSalary) {
            $employee = Employee::where('emp_id', $empId)->firstOrFail();

            // Only these two columns are written, so allowance and bank
            // details already saved for the employee are kept.
            SalarySetup::updateOrCreate(
                ['employee_id' => $employee->id],
                [
                    'salary_type_id' => $monthlyTypeId,
                    'basic_salary' => $basicSalary,
                ]
            );
        }
    }
}
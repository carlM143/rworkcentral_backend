<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkFromHome;
use App\Services\WfhScheduleService;
use Illuminate\Database\Seeder;

class WorkFromHomeSeeder extends Seeder
{
    public function run(WfhScheduleService $schedule): void
    {
        // First user acts as the approver (null if there are no users yet).
        $approverId = User::query()->orderBy('id')->value('id');

        $employees = Employee::query()
            ->with('latestEmployment.workSchedule.days')
            ->get()
            ->keyBy('id');

        $requests = [
            [
                'request_no' => 'WFH-2026-0001',
                'employee_id' => 3, // Carlo Mendoza
                'start_date' => '2026-11-09',
                'end_date' => '2026-11-13',
                'reason' => 'System deployment support; needs focused time away from the office.',
                'supervisor_name' => 'Roger Perez',
                'request_date' => '2026-11-03',
                'status' => 'approved',
                'remarks' => null,
            ],
            [
                'request_no' => 'WFH-2026-0002',
                'employee_id' => 4, // Angela Flores
                'start_date' => '2026-11-10',
                'end_date' => '2026-11-11',
                'reason' => 'Home internet installation; will work remotely during the visit.',
                'supervisor_name' => 'Maria Santos',
                'request_date' => '2026-11-04',
                'status' => 'approved',
                'remarks' => null,
            ],
            [
                'request_no' => 'WFH-2026-0003',
                'employee_id' => 1, // Roger Perez
                'start_date' => '2026-11-16',
                'end_date' => '2026-11-16',
                'reason' => 'Preparing monthly reports.',
                'supervisor_name' => 'Maria Santos',
                'request_date' => '2026-11-05',
                'status' => 'approved',
                'remarks' => null,
            ],
            [
                'request_no' => 'WFH-2026-0004',
                'employee_id' => 2, // Maria Santos
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-06',
                'reason' => 'Payroll cutoff processing.',
                'supervisor_name' => 'Roger Perez',
                'request_date' => '2026-10-28',
                'status' => 'approved',
                'remarks' => null,
            ],
            [
                'request_no' => 'WFH-2026-0005',
                'employee_id' => 2, // Maria Santos
                'start_date' => '2026-11-17',
                'end_date' => '2026-11-18',
                'reason' => 'Family errand in the morning.',
                'supervisor_name' => 'Roger Perez',
                'request_date' => '2026-11-06',
                'status' => 'pending',
                'remarks' => null,
            ],
            [
                'request_no' => 'WFH-2026-0006',
                'employee_id' => 1, // Roger Perez
                'start_date' => '2026-11-03',
                'end_date' => '2026-11-03',
                'reason' => 'Personal appointment.',
                'supervisor_name' => 'Maria Santos',
                'request_date' => '2026-11-02',
                'status' => 'rejected',
                'remarks' => 'Office presence required for the client visit that day.',
            ],
        ];

        foreach ($requests as $data) {
            $employee = $employees->get($data['employee_id']);

            if (! $employee) {
                $this->command?->warn("Skipped {$data['request_no']}: employee {$data['employee_id']} not found.");
                continue;
            }

            // Count scheduled working days the same way the controller does.
            $draft = new WorkFromHome([
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
            ]);
            $draft->setRelation('employee', $employee);
            $numberOfDays = count($schedule->workingDates($draft));

            $decided = in_array($data['status'], ['approved', 'rejected'], true);

            WorkFromHome::query()->updateOrCreate(
                ['request_no' => $data['request_no']],
                [
                    ...$data,
                    'number_of_days' => $numberOfDays,
                    'approved_by' => $decided ? $approverId : null,
                    'approved_at' => $decided ? $data['request_date'] : null,
                ]
            );
        }

        $this->command?->info('Work from home sample requests seeded.');
    }
}
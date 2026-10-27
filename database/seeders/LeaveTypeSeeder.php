<?php

namespace Database\Seeders;

use App\Models\EmploymentType;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::first();
        if (! $admin) {
            return;
        }

        $employmentTypes = EmploymentType::all();
        if ($employmentTypes->isEmpty()) {
            return;
        }

        $leaveTypes = [
            [
                'leave_code' => 'VL',
                'leave_name' => 'Vacation Leave',
                'annual_credits' => 15,
                'is_paid' => true,
            ],
            [
                'leave_code' => 'SL',
                'leave_name' => 'Sick Leave',
                'annual_credits' => 15,
                'is_paid' => true,
            ],
            [
                'leave_code' => 'EL',
                'leave_name' => 'Emergency Leave',
                'annual_credits' => 5,
                'is_paid' => true,
            ],
            [
                'leave_code' => 'BL',
                'leave_name' => 'Bereavement Leave',
                'annual_credits' => 3,
                'is_paid' => true,
            ],
            [
                'leave_code' => 'ML',
                'leave_name' => 'Maternity Leave',
                'annual_credits' => 105,
                'is_paid' => true,
            ],
            [
                'leave_code' => 'PL',
                'leave_name' => 'Paternity Leave',
                'annual_credits' => 7,
                'is_paid' => true,
            ],
            [
                'leave_code' => 'UL',
                'leave_name' => 'Unpaid Leave',
                'annual_credits' => 0,
                'is_paid' => false,
            ],
        ];

        foreach ($leaveTypes as $item) {
            $type = LeaveType::updateOrCreate(
                ['leave_code' => $item['leave_code']],
                [
                    'user_id' => $admin->id,
                    'leave_name' => $item['leave_name'],
                    'status' => 'active',
                ]
            );

            foreach ($employmentTypes as $empType) {
                LeavePolicy::updateOrCreate(
                    [
                        'leave_type_id' => $type->id,
                        'employment_type_id' => $empType->id,
                    ],
                    [
                        'is_eligible' => true,
                        'is_paid' => $item['is_paid'],
                        'annual_credits' => $item['annual_credits'],
                        'min_service_months' => 0,
                        'allow_carry_over' => true,
                        'max_carry_over_days' => 5,
                        'requires_approval' => true,
                        'usage_window' => 'calendar_year',
                        'validity_period' => 'annual',
                        'effective_from' => now()->startOfYear()->toDateString(),
                        'status' => 'approved',
                    ]
                );
            }
        }
    }
}

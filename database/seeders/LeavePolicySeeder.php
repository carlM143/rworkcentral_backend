<?php

namespace Database\Seeders;

use App\Models\EmploymentType;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeavePolicySeeder extends Seeder
{
    public function run(): void
    {
        $regular = EmploymentType::where('name', 'Regular')
            ->firstOrFail();

        $policies = [
            [
                'leave_code' => 'SL',
                'annual_credits' => 2,
                'min_service_months' => 6,
            ],
            [
                'leave_code' => 'SL',
                'annual_credits' => 5,
                'min_service_months' => 12
            ],
            [
                'leave_code' => 'SL',
                'annual_credits' => 5,
                'min_service_months' => 60,
            ],
            [
                'leave_code' => 'ML',
                'annual_credits' => 31,
                'min_service_months' => 12,
            ],
            [
                'leave_code' => 'PL',
                'annual_credits' => 15,
                'min_service_months' => 12,
            ],
        ];

        foreach ($policies as $policy) {
            $leaveType = LeaveType::where(
                'leave_code',
                $policy['leave_code']
            )->firstOrFail();

            LeavePolicy::updateOrCreate(
                [
                    'leave_type_id' => $leaveType->id,
                    'employment_type_id' => $regular->id,
                    'is_paid' => true,
                    'min_service_months' => $policy['min_service_months'],
                    'effective_from' => '2026-01-01',
                ],
                [
                    'is_eligible' => true,
                    'annual_credits' => $policy['annual_credits'],
                    'allow_carry_over' => false,
                    'max_carry_over_days' => 0,
                    'requires_approval' => true,
                    'usage_window' => 'calendar_year',
                    'validity_period' => 'annual',
                    'effective_to' => null,
                    'status' => 'approved',
                ]
            );
        }
    }
}

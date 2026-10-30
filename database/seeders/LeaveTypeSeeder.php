<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@rworkcentral.test')
            ->firstOrFail();

        $leaveTypes = [
            [
                'leave_code' => 'SL',
                'leave_name' => 'Sick Leave',
                'status' => 'active',
            ],
            [
                'leave_code' => 'ML',
                'leave_name' => 'Maternity Leave',
                'status' => 'active',
            ],
            [
                'leave_code' => 'PL',
                'leave_name' => 'Paternity Leave',
                'status' => 'active',
            ],
            [
                'leave_code' => 'BDL',
                'leave_name' => 'Birthday Leave',
                'status' => 'active',
            ],
        ];

        foreach ($leaveTypes as $item) {
            LeaveType::updateOrCreate(
                ['leave_code' => $item['leave_code']],
                [
                    'user_id' => $admin->id,
                    'leave_name' => $item['leave_name'],
                    'status' => $item['status'],
                ]
            );
        }
    }
}

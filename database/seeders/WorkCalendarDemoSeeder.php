<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\Holiday;
use App\Models\HolidayType;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\Position;
use App\Models\Role;
use App\Models\ScheduleType;
use App\Models\TimeLog;
use App\Models\UndertimeRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WorkCalendarDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $company = Company::firstOrFail();
            $locations = $company->locations()->orderBy('id')->get();
            if ($locations->isEmpty()) {
                throw new \RuntimeException('Create company locations before seeding the calendar demo.');
            }
            $department = Department::where('company_id', $company->id)->where('department_code', 'OPS')->first()
                ?? Department::where('company_id', $company->id)->firstOrFail();
            $position = Position::where('department_id', $department->id)->firstOrFail();
            $employmentType = EmploymentType::firstOrFail();
            $leaveTypes = LeaveType::where('status', 'active')->orderBy('id')->get();
            if ($leaveTypes->isEmpty()) {
                throw new \RuntimeException('Create leave types before seeding the calendar demo.');
            }
            $month = now()->startOfMonth();
            $stamp = $month->format('Ym');
            $date = fn (int $day) => $month->copy()->day($day)->format('Y-m-d');
            $scheduleValues = [
                'schedule_code' => 'DEMO-CALENDAR',
                'schedule_type_id' => ScheduleType::firstOrFail()->id,
                'schedule_name' => 'DEMO Calendar Day Shift', 'required_work_hours' => 8,
                'grace_period' => 0, 'break_start' => '12:00:00', 'break_end' => '13:00:00', 'status' => true,
                'created_at' => now(), 'updated_at' => now(),
            ];
            if (Schema::hasColumn('work_schedules', 'time_in')) {
                $scheduleValues['time_in'] = '08:00:00';
                $scheduleValues['time_out'] = '17:00:00';
            }
            $schedule = WorkSchedule::where('schedule_code', 'DEMO-CALENDAR')->first()
                ?? WorkSchedule::findOrFail(DB::table('work_schedules')->insertGetId($scheduleValues));
            foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Sat', 'Sun'] as $day) {
                DB::table('work_schedule_days')->updateOrInsert(
                    ['work_schedule_id' => $schedule->id, 'day_of_week' => $day],
                    ['is_working_day' => true, 'time_in' => '08:00:00', 'time_out' => '17:00:00', 'created_at' => now(), 'updated_at' => now()],
                );
            }

            foreach (['Alex', 'Bea', 'Carlo', 'Dina'] as $index => $name) {
                $location = $locations[$index % $locations->count()];
                $user = User::firstOrCreate(['email' => 'calendar.demo.'.strtolower($name).'@example.test'], [
                    'name' => 'DEMO '.$name.' Calendar', 'role_id' => Role::where('name', 'Employee')->value('id'), 'password' => Str::random(48),
                ]);
                $employee = Employee::where('user_id', $user->id)->first();
                if (! $employee) {
                    do { $number = random_int(90000000, 99999999); } while (Employee::where('emp_id', $number)->exists());
                    $birthday = $month->copy()->subYears(28 + $index)->day(4 + $index * 4);
                    $employee = Employee::create([
                        'user_id' => $user->id, 'department_id' => $department->id, 'emp_id' => $number,
                        'firstname' => 'DEMO '.$name, 'lastname' => 'Calendar', 'birthday' => $birthday->format('Y-m-d'),
                        'age' => $birthday->age, 'civil_status' => 'Single', 'status' => true,
                    ]);
                }
                $employee->contactInformation()->firstOrCreate([], ['email' => $user->email, 'residential_address' => 'DEMO address — '.$location->city]);
                $employee->employments()->firstOrCreate([], [
                    'employment_type_id' => $employmentType->id, 'department_id' => $department->id, 'position_id' => $position->id,
                    'location_id' => $location->id, 'work_schedule_id' => $schedule->id,
                    'date_hired' => $month->copy()->subYears(2 + $index)->day(5 + $index * 4)->format('Y-m-d'),
                ]);

                $leave = LeaveRequest::firstOrCreate(['request_no' => "DEMO-CAL-{$stamp}-LEAVE-{$index}"], [
                    'employee_id' => $employee->id, 'leave_type_id' => $leaveTypes[$index % $leaveTypes->count()]->id,
                    'request_date' => $date(1), 'requested_pay_type' => 'unpaid', 'approved_pay_type' => 'unpaid',
                    'reason' => 'DEMO calendar approved leave', 'approved_by' => $company->admin_id, 'approved_at' => now(), 'status' => 'approved',
                ]);
                $leave->leaveDates()->firstOrCreate(['leave_date' => $date(10 + $index)], [
                    'day_type' => $index % 2 ? 'half_day' : 'whole_day', 'day_value' => $index % 2 ? 0.5 : 1,
                    'requested_pay_type' => 'unpaid', 'approved_pay_type' => 'unpaid',
                ]);

                $late = 10 + $index * 5;
                TimeLog::firstOrCreate(['employee_id' => $employee->id, 'work_date' => $date(3), 'source' => 'demo-calendar'], [
                    'time_in' => $date(3).sprintf(' 08:%02d:00', $late), 'time_out' => $date(3).' 17:00:00',
                    'break_out' => $date(3).' 12:00:00', 'break_in' => $date(3).' 13:00:00',
                    'work_schedule_id' => $schedule->id, 'scheduled_time_in' => $date(3).' 08:00:00', 'scheduled_time_out' => $date(3).' 17:00:00',
                    'late_minutes' => $late, 'raw_late_minutes' => $late, 'chargeable_late_minutes' => $late,
                    'grace_period_minutes' => 0, 'worked_minutes' => 480 - $late, 'status' => 'complete', 'remarks' => 'DEMO calendar late attendance',
                ]);
                OvertimeRequest::firstOrCreate(['request_no' => "DEMO-CAL-{$stamp}-OT-{$index}"], [
                    'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'ot_date' => $date(18 + $index),
                    'start_at' => $date(18 + $index).' 17:00:00', 'end_at' => $date(18 + $index).' 19:00:00',
                    'reason' => 'DEMO calendar approved overtime', 'status' => 'approved', 'approved_by' => $company->admin_id, 'approved_at' => now(),
                ]);
                UndertimeRequest::firstOrCreate(['request_no' => "DEMO-CAL-{$stamp}-UT-{$index}"], [
                    'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'undertime_date' => $date(24 + $index),
                    'requested_time_out' => $date(24 + $index).' 16:00:00', 'scheduled_time_out' => $date(24 + $index).' 17:00:00',
                    'requested_minutes' => 60, 'reason' => 'DEMO calendar approved undertime', 'status' => 'approved',
                    'approved_by' => $company->admin_id, 'approved_at' => now(),
                ]);
            }

            foreach ([['REGULAR', 'DEMO Company Holiday', 6, false, null], ['LOCAL', 'DEMO Local Holiday — '.$locations->first()->location_name, 15, false, $locations->first()->id], ['SPECIAL_WORKING', 'DEMO Special Working Holiday', 23, true, $locations->last()->id]] as [$code, $name, $day, $working, $locationId]) {
                $holiday = Holiday::firstOrCreate(['company_id' => $company->id, 'holiday_name' => $name, 'holiday_date' => $date($day)], [
                    'user_id' => $company->admin_id, 'holiday_type_id' => HolidayType::where('type_code', $code)->firstOrFail()->id,
                    'is_working_day' => $working, 'applies_to' => $locationId ? 'specific_locations' : 'all_locations', 'status' => true,
                ]);
                if ($locationId) $holiday->locations()->syncWithoutDetaching([$locationId]);
            }
        });
        $this->command?->info('Calendar demo created for '.now()->format('F Y').': 4 employees, 3 holidays, 4 leaves, 4 overtime, 4 undertime and 4 late logs.');
    }
}

<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DriversModuleSeeder extends Seeder
{
    private const DEMO_TAG = '[demo]';

    public function run(): void
    {
        $this->call(VehicleSeeder::class);

        DB::transaction(function (): void {
            $base = $this->baseReferences();

            $departments = [
                'OPS' => $this->department($base, 'Operations', 'OPS'),
                'LOG' => $this->department($base, 'Logistics', 'LOG'),
            ];

            $positions = [
                'OPS_DRIVER' => $this->position($departments['OPS'], 'Driver', 'Drives company vehicles for operations trips.'),
                'OPS_HEAD' => $this->position($departments['OPS'], 'Operations Manager', 'Heads the Operations department and approves trip requests.'),
                'OPS_STAFF' => $this->position($departments['OPS'], 'Operations Staff', 'Handles field coordination and deliveries.'),
                'LOG_DRIVER' => $this->position($departments['LOG'], 'Driver', 'Drives company vehicles for logistics trips.'),
                'LOG_HEAD' => $this->position($departments['LOG'], 'Logistics Supervisor', 'Supervises the Logistics department and approves trip requests.'),
                'LOG_STAFF' => $this->position($departments['LOG'], 'Dispatcher', 'Schedules pickups and deliveries.'),
            ];

            // emp_id => [first, middle, last, gender, birthday, position key]
            $people = [
                9001 => ['Mark', null, 'Santos', 'Male', '1990-03-14', 'OPS_DRIVER'],
                9002 => ['Ramon', 'Diaz', 'Cruz', 'Male', '1988-07-22', 'OPS_DRIVER'],
                9003 => ['Jerome', null, 'Dela Cruz', 'Male', '1992-11-02', 'OPS_DRIVER'],
                9004 => ['Neil', 'Gomez', 'Ferrer', 'Male', '1991-05-30', 'LOG_DRIVER'],
                9005 => ['Joshua', null, 'Rivera', 'Male', '1995-01-18', 'LOG_DRIVER'],
                9006 => ['Ricardo', null, 'Lim', 'Male', '1980-09-09', 'OPS_HEAD'],
                9007 => ['Grace', 'Ong', 'Tan', 'Female', '1985-12-01', 'LOG_HEAD'],
                9008 => ['Ana', null, 'Garcia', 'Female', '1996-04-11', 'OPS_STAFF'],
                9009 => ['Leo', null, 'Martinez', 'Male', '1994-08-27', 'LOG_STAFF'],
            ];

            $employeeIds = [];
            $employmentIds = [];

            foreach ($people as $empId => [$first, $middle, $last, $gender, $birthday, $positionKey]) {
                $departmentId = str_starts_with($positionKey, 'OPS') ? $departments['OPS'] : $departments['LOG'];

                [$employeeIds[$empId], $employmentIds[$empId]] = $this->employee(
                    $base,
                    $empId,
                    $first,
                    $middle,
                    $last,
                    $gender,
                    $birthday,
                    $departmentId,
                    $positions[$positionKey],
                );
            }

            // Drivers: emp_id => [licence no, expiry]
            $today = Carbon::today();
            $driverIds = [];

            $licences = [
                9001 => ['N03-2023-18407', $today->copy()->addYears(2)->toDateString()],
                9002 => ['N03-2024-22411', $today->copy()->addYears(3)->toDateString()],
                9003 => ['N03-2022-11184', $today->copy()->addDays(20)->toDateString()], // expiring soon
                9004 => ['N03-2025-00318', $today->copy()->addYears(2)->toDateString()],
                9005 => ['N03-2021-00991', $today->copy()->subMonths(3)->toDateString()], // expired
            ];

            foreach ($licences as $empId => [$licenceNo, $expiry]) {
                $driverIds[$empId] = $this->driver($employmentIds[$empId], $licenceNo, $expiry);
            }

            $this->tripTickets(
                $departments,
                $employeeIds,
                $driverIds,
            );
        });

        $this->command?->info('Drivers module demo data seeded.');
    }

    // =====================================================
    // REFERENCES
    // =====================================================

    /**
     * Existing rows the demo data hangs on (company, admin user,
     * employment type, work schedule, location).
     */
    private function baseReferences(): array
    {
        $department = DB::table('departments')->orderBy('id')->first();
        $companyId = $department->company_id ?? DB::table('companies')->orderBy('id')->value('id');
        $adminId = $department->admin_id ?? DB::table('users')->orderBy('id')->value('id');

        if (! $companyId || ! $adminId) {
            throw new \RuntimeException('Seed companies and users first (php artisan db:seed).');
        }

        $employmentTypeId = DB::table('employment_types')->orderBy('id')->value('id');
        if (! $employmentTypeId) {
            throw new \RuntimeException('Seed employment types first (EmploymentTypeSeeder).');
        }

        return [
            'company_id' => $companyId,
            'admin_id' => $adminId,
            'employment_type_id' => $employmentTypeId,
            'work_schedule_id' => DB::table('work_schedules')->orderBy('id')->value('id'),
            'location_id' => DB::table('locations')->orderBy('id')->value('id'),
        ];
    }

    private function department(array $base, string $name, string $code): int
    {
        $existing = DB::table('departments')
            ->where('company_id', $base['company_id'])
            ->where(fn ($q) => $q->where('department_code', $code)->orWhere('department_name', $name))
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('departments')->insertGetId([
            'admin_id' => $base['admin_id'],
            'company_id' => $base['company_id'],
            'department_name' => $name,
            'department_code' => $code,
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function position(int $departmentId, string $title, string $description): int
    {
        $existing = DB::table('positions')
            ->where('department_id', $departmentId)
            ->where('position', $title)
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('positions')->insertGetId([
            'department_id' => $departmentId,
            'position' => $title,
            'description' => $description,
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: int, 1: int} [employee id, employment id]
     */
    private function employee(
        array $base,
        int $empId,
        string $first,
        ?string $middle,
        string $last,
        string $gender,
        string $birthday,
        int $departmentId,
        int $positionId,
    ): array {
        DB::table('employees')->updateOrInsert(
            ['emp_id' => $empId],
            [
                'department_id' => $departmentId,
                'firstname' => $first,
                'middlename' => $middle,
                'lastname' => $last,
                'gender' => $gender,
                'birthday' => $birthday,
                'age' => Carbon::parse($birthday)->age,
                'civil_status' => 'Single',
                'status' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $employeeId = (int) DB::table('employees')->where('emp_id', $empId)->value('id');

        $employmentId = DB::table('employments')
            ->where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->value('id');

        $employmentData = [
            'employment_type_id' => $base['employment_type_id'],
            'department_id' => $departmentId,
            'location_id' => $base['location_id'],
            'position_id' => $positionId,
            'work_schedule_id' => $base['work_schedule_id'],
            'date_hired' => Carbon::today()->subYears(2)->toDateString(),
            'mobile' => '0917'.str_pad((string) $empId, 7, '0', STR_PAD_LEFT),
            'residential_address' => 'Butuan City',
            'updated_at' => now(),
        ];

        if ($employmentId) {
            DB::table('employments')->where('id', $employmentId)->update($employmentData);
        } else {
            $employmentId = DB::table('employments')->insertGetId($employmentData + [
                'employee_id' => $employeeId,
                'created_at' => now(),
            ]);
        }

        return [$employeeId, (int) $employmentId];
    }

    private function driver(int $employmentId, string $licenceNo, string $expiry): int
    {
        $existing = DB::table('drivers_info')->where('employment_id', $employmentId)->first();

        if ($existing) {
            DB::table('drivers_info')->where('id', $existing->id)->update([
                'expire_date' => $expiry,
                'updated_at' => now(),
            ]);

            return (int) $existing->id;
        }

        // Licence numbers are unique; reuse would mean another record owns it.
        if (DB::table('drivers_info')->where('licence_no', $licenceNo)->exists()) {
            $licenceNo .= '-D'.$employmentId;
        }

        return (int) DB::table('drivers_info')->insertGetId([
            'employment_id' => $employmentId,
            'licence_no' => $licenceNo,
            'drivers_code' => $this->nextDriverCode(),
            'expire_date' => $expiry,
            'image_path' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function nextDriverCode(): string
    {
        $max = DB::table('drivers_info')
            ->where('drivers_code', 'like', 'DRV-%')
            ->pluck('drivers_code')
            ->map(fn ($code) => (int) preg_replace('/\D/', '', (string) $code))
            ->max() ?? 0;

        return 'DRV-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    // =====================================================
    // TRIP TICKETS
    // =====================================================

    /**
     * @param  array<string, int>  $departments
     * @param  array<int, int>  $employees  emp_id => employee id
     * @param  array<int, int>  $drivers  emp_id => drivers_info id
     */
    private function tripTickets(array $departments, array $employees, array $drivers): void
    {
        // Replace previous demo tickets so the seeder can run again.
        $oldIds = DB::table('trip_tickets')->where('remarks', 'like', self::DEMO_TAG.'%')->pluck('id');
        DB::table('trip_ticket_passengers')->whereIn('trip_ticket_id', $oldIds)->delete();
        DB::table('trip_tickets')->whereIn('id', $oldIds)->delete();

        $vehicles = DB::table('vehicles')->pluck('id', 'plate_no');
        $yesterday = Carbon::yesterday()->toDateString();
        $today = Carbon::today()->toDateString();
        $tomorrow = Carbon::tomorrow()->toDateString();

        $opsHead = $employees[9006];
        $logHead = $employees[9007];
        $opsStaff = $employees[9008];
        $logStaff = $employees[9009];

        $tickets = [
            // Yesterday: completed
            [$yesterday, 'OPS', $opsHead, 9001, 'ABC-1234', 'Bajada Office', 'Pickup signed billing documents',
                '08:00', '11:00', '08:05', '10:50', 12380, 12412, 'completed', [$opsStaff]],
            [$yesterday, 'LOG', $logHead, 9004, 'TUV-2345', 'Toril Client Site', 'Client service and document turnover',
                '13:00', '16:00', '13:10', '15:40', 30110, 30168, 'completed', [$logStaff]],

            // Today
            [$today, 'OPS', $opsHead, 9001, 'ABC-1234', 'Davao City Warehouse', 'Delivery coordination',
                '08:00', '12:00', '08:06', '11:52', 12412, 12474, 'completed', [$opsStaff, $logStaff]],
            [$today, 'OPS', $opsStaff, 9002, 'XYZ-9988', 'Toril Client Site', 'Equipment installation support',
                '09:00', '18:00', '09:04', null, 45210, null, 'ongoing', [$opsStaff]],
            [$today, 'LOG', $logHead, 9004, 'DEF-5441', 'Matina Office', 'Billing meeting',
                '14:00', '16:00', null, null, null, null, 'cancelled', [$logStaff]],
            [$today, 'OPS', $opsHead, 9001, 'ABC-1234', 'Bajada Office', 'Document submission',
                '13:30', '15:30', null, null, null, null, 'scheduled', []],

            // Tomorrow: scheduled
            [$tomorrow, 'OPS', $opsHead, 9002, 'XYZ-9988', 'Panacan Facility', 'Operational inspection',
                '08:00', '10:30', null, null, null, null, 'scheduled', [$opsStaff]],
            [$tomorrow, 'LOG', $logHead, 9004, 'TUV-2345', 'Lanang Distribution Hub', 'Inventory count',
                '13:00', '16:00', null, null, null, null, 'scheduled', [$logStaff, $opsStaff]],
        ];

        foreach ($tickets as [
            $date, $deptKey, $requester, $driverEmpId, $plate, $destination, $purpose,
            $plannedOut, $plannedIn, $actualOut, $actualIn, $odoOut, $odoIn, $status, $passengers,
        ]) {
            if (! isset($vehicles[$plate], $drivers[$driverEmpId])) {
                continue;
            }

            $ticketId = DB::table('trip_tickets')->insertGetId([
                'ticket_no' => $this->nextTicketNo($date),
                'requesting_department_id' => $departments[$deptKey],
                'requested_by' => $requester,
                'trip_date' => $date,
                'scheduled_departure' => $plannedOut,
                'expected_return' => $plannedIn,
                'driver_id' => $drivers[$driverEmpId],
                'vehicle_id' => $vehicles[$plate],
                'destination' => $destination,
                'purpose' => $purpose,
                'departed_at' => $actualOut,
                'returned_at' => $actualIn,
                'odometer_out' => $odoOut,
                'odometer_in' => $odoIn,
                'status' => $status,
                'remarks' => $status === 'cancelled'
                    ? self::DEMO_TAG.' Meeting was rescheduled.'
                    : self::DEMO_TAG,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (array_unique($passengers) as $employeeId) {
                DB::table('trip_ticket_passengers')->insert([
                    'trip_ticket_id' => $ticketId,
                    'employee_id' => $employeeId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function nextTicketNo(string $date): string
    {
        $prefix = 'TT-'.Carbon::parse($date)->year.'-';

        $last = DB::table('trip_tickets')
            ->where('ticket_no', 'like', $prefix.'%')
            ->orderByDesc('ticket_no')
            ->value('ticket_no');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
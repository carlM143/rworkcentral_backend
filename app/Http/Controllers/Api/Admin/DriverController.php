<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverInfo;
use App\Models\Employee;
use App\Models\TripTicket;
use App\Services\DriverAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Drivers (drivers_info).
 *
 * An employee becomes eligible as a driver when the position of their
 * current employment contains the word "Driver" (e.g. "Driver" under
 * Operations or Marketing). HR then registers the license details here.
 */
class DriverController extends Controller
{
    public function __construct(private readonly DriverAvailabilityService $availability)
    {
    }

    /**
     * GET /admin/drivers/availability?date=YYYY-MM-DD
     */
    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $date = Carbon::parse($validated['date'] ?? now()->toDateString());
        $onLeave = $this->availability->employeesOnLeave($date);
        $trips = $this->availability->tripsByDriver($date);

        $drivers = DriverInfo::query()
            ->with($this->relations())
            ->orderBy('drivers_code')
            ->get()
            ->map(fn (DriverInfo $driver): array => $this->format($driver, $date, $onLeave, $trips))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Driver availability retrieved.',
            'data' => $drivers,
        ]);
    }

    /**
     * GET /admin/drivers/eligible-employees
     * Active employees whose current position is a driver position and who
     * are not registered as drivers yet.
     */
    public function eligibleEmployees(): JsonResponse
    {
        $registered = DriverInfo::query()->pluck('employment_id');

        $employees = Employee::query()
            ->where('status', true)
            ->whereHas('latestEmployment.position', fn ($q) => $q->where('position', 'like', '%driver%'))
            ->with([
                'latestEmployment',
                'latestEmployment.department:id,department_name',
                'latestEmployment.position:id,position',
            ])
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get()
            ->filter(fn (Employee $e) => $e->latestEmployment && ! $registered->contains($e->latestEmployment->id))
            ->map(fn (Employee $e): array => $this->formatEmployee($e))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Eligible employees retrieved.',
            'data' => $employees,
        ]);
    }

    /**
     * GET /admin/drivers/next-code
     */
    public function nextCode(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Next driver code generated.',
            'data' => ['drivers_code' => $this->generateCode()],
        ]);
    }

    /**
     * POST /admin/drivers (multipart)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'drivers_code' => ['nullable', 'string', 'max:50', 'unique:drivers_info,drivers_code'],
            'licence_no' => ['required', 'string', 'max:50', 'unique:drivers_info,licence_no'],
            'expire_date' => ['required', 'date', 'after:today'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'expire_date.after' => 'The license is already expired.',
            'licence_no.unique' => 'This license number is already registered.',
            'drivers_code.unique' => 'This driver code is already used.',
        ]);

        $storedPath = null;

        try {
            $driver = DB::transaction(function () use ($request, $validated, &$storedPath): DriverInfo {
                $employee = Employee::query()
                    ->where('status', true)
                    ->with('latestEmployment.position:id,position')
                    ->lockForUpdate()
                    ->find($validated['employee_id']);

                $employment = $employee?->latestEmployment;

                if (! $employment || ! str_contains(strtolower((string) $employment->position?->position), 'driver')) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'The employee\'s current position must be a Driver position.',
                    ]);
                }

                if (DriverInfo::query()->where('employment_id', $employment->id)->exists()) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'This employee is already registered as a driver.',
                    ]);
                }

                $driver = DriverInfo::create([
                    'employment_id' => $employment->id,
                    'licence_no' => strtoupper(trim($validated['licence_no'])),
                    'drivers_code' => strtoupper(trim($validated['drivers_code'] ?? '')) ?: $this->generateCode(),
                    'expire_date' => $validated['expire_date'],
                ]);

                if ($request->hasFile('image')) {
                    $storedPath = $request->file('image')->store("drivers/{$driver->id}", 'public');
                    $driver->update(['image_path' => $storedPath]);
                }

                return $driver;
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('public')->delete($storedPath);
            }

            throw $exception;
        }

        $date = now();
        $driver->load($this->relations());

        return response()->json([
            'success' => true,
            'message' => 'Driver added successfully.',
            'data' => $this->format(
                $driver,
                $date,
                $this->availability->employeesOnLeave($date),
                $this->availability->tripsByDriver($date),
            ),
        ], 201);
    }

    /**
     * GET /admin/drivers/{driverInfo}
     */
    public function show(DriverInfo $driverInfo): JsonResponse
    {
        $date = now();
        $driverInfo->load($this->relations());

        return response()->json([
            'success' => true,
            'message' => 'Driver retrieved.',
            'data' => $this->format(
                $driverInfo,
                $date,
                $this->availability->employeesOnLeave($date),
                $this->availability->tripsByDriver($date),
            ),
        ]);
    }

    /**
     * POST /admin/drivers/{driverInfo}  (multipart, used for edits because
     * PHP does not parse file uploads on PUT/PATCH requests)
     * Renew or correct the license, change the driver code or photo.
     */
    public function update(Request $request, DriverInfo $driverInfo): JsonResponse
    {
        $validated = $request->validate([
            'drivers_code' => ['required', 'string', 'max:50', 'unique:drivers_info,drivers_code,'.$driverInfo->id],
            'licence_no' => ['required', 'string', 'max:50', 'unique:drivers_info,licence_no,'.$driverInfo->id],
            'expire_date' => ['required', 'date'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],
        ], [
            'licence_no.unique' => 'This license number is already registered.',
            'drivers_code.unique' => 'This driver code is already used.',
        ]);

        $oldImage = $driverInfo->image_path;
        $newImage = null;

        try {
            if ($request->hasFile('image')) {
                $newImage = $request->file('image')->store("drivers/{$driverInfo->id}", 'public');
            }

            $imagePath = $newImage
                ?? ($request->boolean('remove_image') ? null : $oldImage);

            $driverInfo->update([
                'drivers_code' => strtoupper(trim($validated['drivers_code'])),
                'licence_no' => strtoupper(trim($validated['licence_no'])),
                'expire_date' => $validated['expire_date'],
                'image_path' => $imagePath,
            ]);
        } catch (Throwable $exception) {
            if ($newImage) {
                Storage::disk('public')->delete($newImage);
            }

            throw $exception;
        }

        // Remove the replaced or removed photo only after the save succeeded.
        if ($oldImage && $oldImage !== $driverInfo->image_path) {
            Storage::disk('public')->delete($oldImage);
        }

        return $this->respond($driverInfo, 'Driver updated successfully.');
    }

    /**
     * PATCH /admin/drivers/{driverInfo}/deactivate
     * Keeps the driver and their trip history, but hides them from new
     * trip tickets. Blocked while they still have upcoming trips.
     */
    public function deactivate(DriverInfo $driverInfo): JsonResponse
    {
        if ($driverInfo->status === 'inactive') {
            return $this->respond($driverInfo, 'Driver is already inactive.');
        }

        $pending = TripTicket::query()
            ->where('driver_id', $driverInfo->id)
            ->whereIn('status', ['scheduled', 'ongoing'])
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->orderBy('trip_date')
            ->pluck('ticket_no');

        if ($pending->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => 'Reassign or cancel the driver\'s upcoming trips first: '.$pending->implode(', ').'.',
            ]);
        }

        $driverInfo->update(['status' => 'inactive']);

        return $this->respond($driverInfo, 'Driver deactivated.');
    }

    /**
     * PATCH /admin/drivers/{driverInfo}/activate
     */
    public function activate(DriverInfo $driverInfo): JsonResponse
    {
        $driverInfo->loadMissing('employment.employee:id,status');

        if (! $driverInfo->employment?->employee?->status) {
            throw ValidationException::withMessages([
                'status' => 'The employee record is inactive. Reactivate the employee first.',
            ]);
        }

        $driverInfo->update(['status' => 'active']);

        return $this->respond($driverInfo, 'Driver activated.');
    }

    // =====================================================
    // HELPERS
    // =====================================================

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'employment',
            'employment.employee:id,emp_id,firstname,middlename,lastname,suffix,status',
            'employment.department:id,department_name',
            'employment.position:id,position',
            'employment.workSchedule:id,schedule_code,schedule_name',
            'employment.workSchedule.days:id,work_schedule_id,day_of_week,is_working_day',
        ];
    }

    private function respond(DriverInfo $driver, string $message): JsonResponse
    {
        $date = now();
        $driver->load($this->relations());

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->format(
                $driver,
                $date,
                $this->availability->employeesOnLeave($date),
                $this->availability->tripsByDriver($date),
            ),
        ]);
    }

    private function generateCode(): string
    {
        $max = DriverInfo::query()
            ->where('drivers_code', 'like', 'DRV-%')
            ->pluck('drivers_code')
            ->map(fn (string $code) => (int) preg_replace('/\D/', '', $code))
            ->max() ?? 0;

        return 'DRV-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  Collection<int, int>  $onLeave
     * @param  Collection<int, Collection>  $tripsByDriver
     */
    private function format(DriverInfo $driver, Carbon $date, Collection $onLeave, Collection $tripsByDriver): array
    {
        $employment = $driver->employment;
        $employee = $employment?->employee;

        return [
            'id' => $driver->id,
            'drivers_code' => $driver->drivers_code,
            'licence_no' => $driver->licence_no,
            'expire_date' => $driver->expire_date?->toDateString(),
            'image_path' => $driver->image_path ? Storage::disk('public')->url($driver->image_path) : null,
            'status' => $driver->status ?? 'active',
            'employee' => [
                'id' => $employee?->id,
                'emp_id' => (string) ($employee?->emp_id ?? ''),
                'name' => $this->fullName($employee),
                'department' => $employment?->department?->department_name,
                'position' => $employment?->position?->position,
            ],
            'availability' => $this->availability->forDate(
                $driver,
                $date,
                $onLeave,
                $tripsByDriver->get($driver->id, collect()),
            ),
        ];
    }

    private function formatEmployee(Employee $employee): array
    {
        $employment = $employee->latestEmployment;

        return [
            'id' => $employee->id,
            'emp_id' => (string) $employee->emp_id,
            'name' => $this->fullName($employee),
            'department' => $employment?->department?->department_name,
            'position' => $employment?->position?->position,
        ];
    }

    private function fullName(?Employee $employee): string
    {
        $name = collect([
            $employee?->firstname,
            $employee?->middlename,
            $employee?->lastname,
            $employee?->suffix,
        ])->filter()->implode(' ');

        return $name !== '' ? $name : 'Unknown Employee';
    }
}
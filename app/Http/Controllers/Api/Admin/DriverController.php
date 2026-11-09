<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverInfo;
use App\Models\Employee;
use App\Services\DriverAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

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
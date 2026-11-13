<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DriverInfo;
use App\Models\Employee;
use App\Models\TripTicket;
use App\Models\Vehicle;
use App\Services\DriverAvailabilityService;
use App\Services\TripScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Trip Tickets.
 *
 * Flow: scheduled -> ongoing (depart: time + odometer out)
 *       -> completed (return: time + odometer in); scheduled -> cancelled.
 */
class TripTicketController extends Controller
{
    /** Department head = position title contains Head, Manager or Supervisor. */
    private const HEAD_PATTERN = '/\b(head|manager|supervisor)\b/i';

    private const UNAVAILABLE = [
        'rest_day' => 'Rest day',
        'on_leave' => 'On leave',
        'license_expired' => 'License expired',
    ];

    public function __construct(
        private readonly TripScheduleService $schedule,
        private readonly DriverAvailabilityService $availability,
    ) {
    }

    // =====================================================
    // LIST
    // =====================================================

    /**
     * GET /admin/trip-tickets
     *   ?page=&per_page=&search=&status=&driver_id=&department_id=&date_from=&date_to=
     * Paginated list plus today's stats and the filter dropdown options.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:scheduled,ongoing,completed,cancelled'],
            'driver_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $query = TripTicket::query()
            ->with($this->relations())
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['driver_id'] ?? null, fn ($q, $id) => $q->where('driver_id', $id))
            ->when($validated['department_id'] ?? null, fn ($q, $id) => $q->where('requesting_department_id', $id))
            ->when($validated['date_from'] ?? null, fn ($q, $date) => $q->whereDate('trip_date', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($q, $date) => $q->whereDate('trip_date', '<=', $date))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $name = fn ($e) => $e->where('firstname', 'like', $like)->orWhere('lastname', 'like', $like);

                $q->where(function ($w) use ($like, $name) {
                    $w->where('ticket_no', 'like', $like)
                        ->orWhere('destination', 'like', $like)
                        ->orWhere('purpose', 'like', $like)
                        ->orWhereHas('vehicle', fn ($v) => $v->where('plate_no', 'like', $like))
                        ->orWhereHas('driver.employment.employee', $name)
                        ->orWhereHas('requester', $name)
                        ->orWhereHas('passengers', $name);
                });
            })
            ->orderByDesc('trip_date')
            ->orderByDesc('scheduled_departure')
            ->orderByDesc('id');

        $page = $query->paginate((int) ($validated['per_page'] ?? 10));
        $lastOdometer = $this->schedule->lastOdometerByVehicle();

        return response()->json([
            'success' => true,
            'message' => 'Trip tickets retrieved.',
            'data' => collect($page->items())
                ->map(fn (TripTicket $ticket): array => $this->format($ticket, $lastOdometer))
                ->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem() ?? 0,
                'to' => $page->lastItem() ?? 0,
            ],
            'stats' => $this->todayStats(),
            'filters' => $this->filterOptions(),
        ]);
    }

    /**
     * GET /admin/trip-tickets/{tripTicket}
     */
    public function show(TripTicket $tripTicket): JsonResponse
    {
        return $this->respond($tripTicket, 'Trip ticket retrieved.');
    }

    // =====================================================
    // FORM OPTIONS
    // =====================================================

    /**
     * GET /admin/trip-tickets/departments
     */
    public function departments(): JsonResponse
    {
        $departments = Department::query()
            ->where('status', true)
            ->orderBy('department_name')
            ->get(['id', 'department_name'])
            ->map(fn (Department $d): array => ['id' => $d->id, 'name' => $d->department_name])
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Departments retrieved.',
            'data' => $departments,
        ]);
    }

    /**
     * GET /admin/trip-tickets/requesters?department_id=
     * Active employees whose current employment is in the department, head first.
     */
    public function requesters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ]);

        $departmentId = (int) $validated['department_id'];

        $requesters = Employee::query()
            ->where('status', true)
            ->whereHas('latestEmployment', fn ($q) => $q->where('department_id', $departmentId))
            ->with(['latestEmployment', 'latestEmployment.position:id,position'])
            ->get()
            ->filter(fn (Employee $e) => (int) $e->latestEmployment?->department_id === $departmentId)
            ->map(function (Employee $e): array {
                $position = (string) ($e->latestEmployment?->position?->position ?? '');

                return [
                    'id' => $e->id,
                    'emp_id' => (string) $e->emp_id,
                    'name' => $this->fullName($e),
                    'position' => $position !== '' ? $position : '—',
                    'is_head' => (bool) preg_match(self::HEAD_PATTERN, $position),
                ];
            })
            ->sortBy([['is_head', 'desc'], ['name', 'asc']])
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Requesters retrieved.',
            'data' => $requesters,
        ]);
    }

    /**
     * GET /admin/trip-tickets/options?date=&start=&end=&department_id=
     * Drivers, vehicles and passengers for the time window, each with the
     * reason when it cannot be selected.
     */
    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i', 'after:start'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'exclude_ticket_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Trip options retrieved.',
            'data' => $this->buildOptions(
                Carbon::parse($validated['date']),
                $validated['start'],
                $validated['end'],
                isset($validated['department_id']) ? (int) $validated['department_id'] : null,
                isset($validated['exclude_ticket_id']) ? (int) $validated['exclude_ticket_id'] : null,
            ),
        ]);
    }

    // =====================================================
    // CREATE
    // =====================================================

    /**
     * POST /admin/trip-tickets
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateTicket($request);
        $passengerIds = array_values(array_unique($validated['passenger_ids'] ?? []));

        $ticket = DB::transaction(function () use ($request, $validated, $passengerIds): TripTicket {
            $this->assertBookable($validated, $passengerIds);

            $ticket = TripTicket::create([
                'ticket_no' => $this->nextTicketNo($validated['trip_date']),
                'requesting_department_id' => $validated['requesting_department_id'],
                'requested_by' => $validated['requested_by'],
                'trip_date' => $validated['trip_date'],
                'scheduled_departure' => $validated['scheduled_departure'],
                'expected_return' => $validated['expected_return'],
                'driver_id' => $validated['driver_id'],
                'vehicle_id' => $validated['vehicle_id'],
                'destination' => trim($validated['destination']),
                'purpose' => trim($validated['purpose']),
                'remarks' => isset($validated['remarks']) ? trim($validated['remarks']) ?: null : null,
                'status' => 'scheduled',
                'created_by' => $request->user()?->id,
            ]);

            $ticket->passengers()->sync($passengerIds);

            return $ticket;
        });

        return $this->respond($ticket, "Trip ticket {$ticket->ticket_no} created.", 201);
    }

    /**
     * PUT /admin/trip-tickets/{tripTicket}
     * Edit a ticket that has not departed yet.
     */
    public function update(Request $request, TripTicket $tripTicket): JsonResponse
    {
        $validated = $this->validateTicket($request);
        $passengerIds = array_values(array_unique($validated['passenger_ids'] ?? []));

        $ticket = DB::transaction(function () use ($tripTicket, $validated, $passengerIds): TripTicket {
            $ticket = TripTicket::query()->lockForUpdate()->findOrFail($tripTicket->id);

            if ($ticket->status !== 'scheduled') {
                throw ValidationException::withMessages([
                    'status' => 'Only scheduled trips can be edited.',
                ]);
            }

            $this->assertBookable($validated, $passengerIds, $ticket->id);

            $ticket->update([
                'requesting_department_id' => $validated['requesting_department_id'],
                'requested_by' => $validated['requested_by'],
                'trip_date' => $validated['trip_date'],
                'scheduled_departure' => $validated['scheduled_departure'],
                'expected_return' => $validated['expected_return'],
                'driver_id' => $validated['driver_id'],
                'vehicle_id' => $validated['vehicle_id'],
                'destination' => trim($validated['destination']),
                'purpose' => trim($validated['purpose']),
                'remarks' => isset($validated['remarks']) ? trim($validated['remarks']) ?: null : null,
            ]);

            $ticket->passengers()->sync($passengerIds);

            return $ticket;
        });

        return $this->respond($ticket, "Trip ticket {$ticket->ticket_no} updated.");
    }

    // =====================================================
    // STATUS CHANGES
    // =====================================================

    /**
     * PATCH /admin/trip-tickets/{tripTicket}/depart
     */
    public function depart(Request $request, TripTicket $tripTicket): JsonResponse
    {
        $validated = $request->validate([
            'departed_at' => ['required', 'date_format:H:i'],
            'odometer_out' => ['required', 'integer', 'min:0'],
        ]);

        $ticket = DB::transaction(function () use ($tripTicket, $validated): TripTicket {
            $ticket = TripTicket::query()->lockForUpdate()->findOrFail($tripTicket->id);

            if ($ticket->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => 'Only scheduled trips can depart.']);
            }

            $last = $this->schedule->lastOdometer($ticket->vehicle_id, $ticket->id);
            if ($last !== null && (int) $validated['odometer_out'] < $last) {
                throw ValidationException::withMessages([
                    'odometer_out' => 'Odometer cannot be lower than the last reading ('.number_format($last).' km).',
                ]);
            }

            $otherOngoing = TripTicket::query()
                ->whereKeyNot($ticket->id)
                ->where('status', 'ongoing')
                ->where(fn ($q) => $q->where('driver_id', $ticket->driver_id)->orWhere('vehicle_id', $ticket->vehicle_id))
                ->first();
            if ($otherOngoing) {
                throw ValidationException::withMessages([
                    'status' => "Driver or vehicle is still on {$otherOngoing->ticket_no}. Record its return first.",
                ]);
            }

            $ticket->update([
                'status' => 'ongoing',
                'departed_at' => $validated['departed_at'],
                'odometer_out' => $validated['odometer_out'],
            ]);

            return $ticket;
        });

        return $this->respond($ticket, "{$ticket->ticket_no} departed.");
    }

    /**
     * PATCH /admin/trip-tickets/{tripTicket}/return
     */
    public function returnTrip(Request $request, TripTicket $tripTicket): JsonResponse
    {
        $validated = $request->validate([
            'returned_at' => ['required', 'date_format:H:i'],
            'odometer_in' => ['required', 'integer', 'min:0'],
        ]);

        $ticket = DB::transaction(function () use ($tripTicket, $validated): TripTicket {
            $ticket = TripTicket::query()->lockForUpdate()->findOrFail($tripTicket->id);

            if ($ticket->status !== 'ongoing') {
                throw ValidationException::withMessages(['status' => 'Only ongoing trips can be returned.']);
            }

            if ($validated['returned_at'] <= $this->schedule->time($ticket->departed_at)) {
                throw ValidationException::withMessages([
                    'returned_at' => 'Return time must be after the departure time.',
                ]);
            }

            if ((int) $validated['odometer_in'] < (int) $ticket->odometer_out) {
                throw ValidationException::withMessages([
                    'odometer_in' => 'Odometer cannot be lower than the reading at departure ('.number_format($ticket->odometer_out).' km).',
                ]);
            }

            $ticket->update([
                'status' => 'completed',
                'returned_at' => $validated['returned_at'],
                'odometer_in' => $validated['odometer_in'],
            ]);

            return $ticket;
        });

        return $this->respond($ticket, "{$ticket->ticket_no} completed.");
    }

    /**
     * PATCH /admin/trip-tickets/{tripTicket}/cancel
     */
    public function cancel(Request $request, TripTicket $tripTicket): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ], [
            'remarks.required' => 'Enter the reason for cancelling.',
        ]);

        $ticket = DB::transaction(function () use ($tripTicket, $validated): TripTicket {
            $ticket = TripTicket::query()->lockForUpdate()->findOrFail($tripTicket->id);

            if ($ticket->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => 'Only scheduled trips can be cancelled.']);
            }

            $ticket->update([
                'status' => 'cancelled',
                'remarks' => trim($validated['remarks']),
            ]);

            return $ticket;
        });

        return $this->respond($ticket, "{$ticket->ticket_no} cancelled.");
    }

    // =====================================================
    // HELPERS
    // =====================================================

    /**
     * @return array{drivers: array, vehicles: array, passengers: array}
     */
    private function buildOptions(Carbon $date, string $start, string $end, ?int $departmentId, ?int $excludeId = null): array
    {
        $busy = $this->schedule->overlapping($date->toDateString(), $start, $end, $excludeId);
        $window = Carbon::createFromFormat('H:i', $start)->format('g:i A').'–'.Carbon::createFromFormat('H:i', $end)->format('g:i A');

        // Drivers
        $onLeave = $this->availability->employeesOnLeave($date);
        $tripsByDriver = $this->availability->tripsByDriver($date);

        $drivers = DriverInfo::query()
            ->with([
                'employment',
                'employment.employee:id,firstname,middlename,lastname,suffix,status',
                'employment.department:id,department_name',
                'employment.workSchedule.days:id,work_schedule_id,day_of_week,is_working_day',
            ])
            ->get()
            ->filter(fn (DriverInfo $d) => ($d->status ?? 'active') === 'active' && (bool) $d->employment?->employee?->status)
            ->map(function (DriverInfo $d) use ($date, $onLeave, $tripsByDriver, $busy, $window, $departmentId): array {
                $base = [
                    'id' => $d->id,
                    'name' => $this->fullName($d->employment?->employee),
                    'drivers_code' => $d->drivers_code,
                    'department' => $d->employment?->department?->department_name,
                    'same_department' => $departmentId !== null && (int) $d->employment?->department_id === $departmentId,
                ];

                $status = $this->availability->forDate($d, $date, $onLeave, $tripsByDriver->get($d->id, collect()))['status'];
                if (isset(self::UNAVAILABLE[$status])) {
                    return $base + ['available' => false, 'reason' => self::UNAVAILABLE[$status]];
                }

                $clash = $busy->firstWhere('driver_id', $d->id);
                if ($clash) {
                    return $base + ['available' => false, 'reason' => "On {$clash->ticket_no} during {$window}"];
                }

                return $base + ['available' => true, 'reason' => null];
            })
            ->sortBy([['same_department', 'desc'], ['available', 'desc'], ['name', 'asc']])
            ->values()
            ->all();

        // Vehicles (inactive ones are hidden, maintenance ones are shown as unavailable)
        $vehicles = Vehicle::query()
            ->where('status', '!=', 'inactive')
            ->orderBy('plate_no')
            ->get()
            ->map(function (Vehicle $v) use ($busy): array {
                $base = [
                    'id' => $v->id,
                    'plate_no' => $v->plate_no,
                    'description' => $v->description,
                    'capacity' => $v->capacity,
                ];

                if ($v->status === 'maintenance') {
                    return $base + ['available' => false, 'reason' => 'Under maintenance'];
                }

                $clash = $busy->firstWhere('vehicle_id', $v->id);

                return $clash
                    ? $base + ['available' => false, 'reason' => "In use on {$clash->ticket_no}"]
                    : $base + ['available' => true, 'reason' => null];
            })
            ->sortBy([['available', 'desc'], ['plate_no', 'asc']])
            ->values()
            ->all();

        // Passengers: active employees who are not registered drivers
        $driverEmploymentIds = DriverInfo::query()->pluck('employment_id');

        $passengers = Employee::query()
            ->where('status', true)
            ->with(['latestEmployment', 'latestEmployment.department:id,department_name'])
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get()
            ->filter(fn (Employee $e) => ! $driverEmploymentIds->contains($e->latestEmployment?->id))
            ->map(function (Employee $e) use ($busy): array {
                $base = [
                    'id' => $e->id,
                    'emp_id' => (string) $e->emp_id,
                    'name' => $this->fullName($e),
                    'department' => $e->latestEmployment?->department?->department_name,
                ];

                $clash = $busy->first(fn (TripTicket $t) => $t->passengers->contains('id', $e->id));

                return $clash
                    ? $base + ['available' => false, 'reason' => "On {$clash->ticket_no}"]
                    : $base + ['available' => true, 'reason' => null];
            })
            ->values()
            ->all();

        return ['drivers' => $drivers, 'vehicles' => $vehicles, 'passengers' => $passengers];
    }

    private function validateTicket(Request $request): array
    {
        return $request->validate([
            'requesting_department_id' => ['required', 'integer', 'exists:departments,id'],
            'requested_by' => ['required', 'integer', 'exists:employees,id'],
            'trip_date' => ['required', 'date', 'after_or_equal:today'],
            'scheduled_departure' => ['required', 'date_format:H:i'],
            'expected_return' => ['required', 'date_format:H:i', 'after:scheduled_departure'],
            'driver_id' => ['required', 'integer', 'exists:drivers_info,id'],
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'destination' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:255'],
            'passenger_ids' => ['nullable', 'array'],
            'passenger_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], [
            'trip_date.after_or_equal' => 'The trip date cannot be in the past.',
            'expected_return.after' => 'Expected return must be after the departure time.',
        ]);
    }

    /**
     * Requester, driver, vehicle and passengers must all be free for the
     * window. $excludeId ignores the ticket being edited.
     *
     * @param  array<int, int>  $passengerIds
     */
    private function assertBookable(array $validated, array $passengerIds, ?int $excludeId = null): void
    {
        $requester = Employee::query()->with('latestEmployment')->find($validated['requested_by']);
        if ((int) $requester?->latestEmployment?->department_id !== (int) $validated['requesting_department_id']) {
            throw ValidationException::withMessages([
                'requested_by' => 'The requester must belong to the requesting department.',
            ]);
        }

        // Lock the day's tickets so two people cannot double-book.
        TripTicket::query()->whereDate('trip_date', $validated['trip_date'])->lockForUpdate()->get(['id']);

        $options = $this->buildOptions(
            Carbon::parse($validated['trip_date']),
            $validated['scheduled_departure'],
            $validated['expected_return'],
            (int) $validated['requesting_department_id'],
            $excludeId,
        );

        $driver = collect($options['drivers'])->firstWhere('id', (int) $validated['driver_id']);
        if (! $driver || ! $driver['available']) {
            throw ValidationException::withMessages([
                'driver_id' => 'Driver is not available: '.($driver['reason'] ?? 'not found or inactive').'.',
            ]);
        }

        $vehicle = collect($options['vehicles'])->firstWhere('id', (int) $validated['vehicle_id']);
        if (! $vehicle || ! $vehicle['available']) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle is not available: '.($vehicle['reason'] ?? 'not found or inactive').'.',
            ]);
        }

        if (count($passengerIds) > $vehicle['capacity']) {
            throw ValidationException::withMessages([
                'passenger_ids' => "{$vehicle['plate_no']} can carry up to {$vehicle['capacity']} passenger(s).",
            ]);
        }

        $busyPassenger = collect($options['passengers'])
            ->first(fn (array $p) => in_array($p['id'], $passengerIds, true) && ! $p['available']);
        if ($busyPassenger) {
            throw ValidationException::withMessages([
                'passenger_ids' => "{$busyPassenger['name']} is already {$busyPassenger['reason']}.",
            ]);
        }
    }

    private function todayStats(): array
    {
        $today = TripTicket::query()
            ->whereDate('trip_date', now()->toDateString())
            ->where('status', '!=', 'cancelled')
            ->get(['status', 'odometer_out', 'odometer_in']);

        return [
            'trips_today' => $today->count(),
            'ongoing' => TripTicket::query()->where('status', 'ongoing')->count(),
            'completed_today' => $today->where('status', 'completed')->count(),
            'km_today' => (int) $today
                ->filter(fn ($t) => $t->odometer_in !== null && $t->odometer_out !== null)
                ->sum(fn ($t) => $t->odometer_in - $t->odometer_out),
        ];
    }

    private function filterOptions(): array
    {
        $drivers = DriverInfo::query()
            ->whereIn('id', TripTicket::query()->select('driver_id')->distinct())
            ->with('employment.employee:id,firstname,middlename,lastname,suffix')
            ->get()
            ->map(fn (DriverInfo $d): array => ['id' => $d->id, 'name' => $this->fullName($d->employment?->employee)])
            ->sortBy('name')
            ->values();

        $departments = Department::query()
            ->whereIn('id', TripTicket::query()->select('requesting_department_id')->distinct())
            ->orderBy('department_name')
            ->get(['id', 'department_name'])
            ->map(fn (Department $d): array => ['id' => $d->id, 'name' => $d->department_name])
            ->values();

        return ['drivers' => $drivers, 'departments' => $departments];
    }

    private function nextTicketNo(string $tripDate): string
    {
        $prefix = 'TT-'.Carbon::parse($tripDate)->year.'-';

        $last = TripTicket::query()
            ->where('ticket_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('ticket_no')
            ->value('ticket_no');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function respond(TripTicket $ticket, string $message, int $status = 200): JsonResponse
    {
        $ticket->load($this->relations());

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->format($ticket, $this->schedule->lastOdometerByVehicle()),
        ], $status);
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'requestingDepartment:id,department_name',
            'requester:id,emp_id,firstname,middlename,lastname,suffix',
            'requester.latestEmployment',
            'requester.latestEmployment.position:id,position',
            'driver',
            'driver.employment',
            'driver.employment.employee:id,firstname,middlename,lastname,suffix',
            'vehicle',
            'passengers',
            'passengers.latestEmployment',
            'passengers.latestEmployment.department:id,department_name',
        ];
    }

    /**
     * @param  Collection<int, int>  $lastOdometer  highest reading per vehicle
     */
    private function format(TripTicket $t, Collection $lastOdometer): array
    {
        $departedAt = $this->schedule->time($t->departed_at);
        $returnedAt = $this->schedule->time($t->returned_at);
        $reading = $lastOdometer->get($t->vehicle_id);

        return [
            'id' => $t->id,
            'ticket_no' => $t->ticket_no,
            'trip_date' => $t->trip_date?->toDateString(),
            'status' => $t->status,
            'requesting_department' => $t->requestingDepartment
                ? ['id' => $t->requestingDepartment->id, 'name' => $t->requestingDepartment->department_name]
                : null,
            'requested_by' => $t->requester
                ? [
                    'id' => $t->requester->id,
                    'name' => $this->fullName($t->requester),
                    'position' => $t->requester->latestEmployment?->position?->position ?? '—',
                ]
                : null,
            'driver' => [
                'id' => $t->driver_id,
                'name' => $this->fullName($t->driver?->employment?->employee),
                'drivers_code' => $t->driver?->drivers_code ?? '—',
            ],
            'vehicle' => [
                'id' => $t->vehicle?->id,
                'plate_no' => $t->vehicle?->plate_no,
                'description' => $t->vehicle?->description,
                'capacity' => $t->vehicle?->capacity,
            ],
            'last_odometer' => $reading && $reading > 0 ? $reading : null,
            'destination' => $t->destination,
            'purpose' => $t->purpose,
            'scheduled_departure' => $this->schedule->time($t->scheduled_departure),
            'expected_return' => $this->schedule->time($t->expected_return),
            'departed_at' => $departedAt,
            'returned_at' => $returnedAt,
            'odometer_out' => $t->odometer_out,
            'odometer_in' => $t->odometer_in,
            'distance_km' => $t->odometer_in !== null && $t->odometer_out !== null ? $t->odometer_in - $t->odometer_out : null,
            'duration_minutes' => $this->schedule->minutesBetween($departedAt, $returnedAt),
            'passengers' => $t->passengers->map(fn (Employee $p): array => [
                'id' => $p->id,
                'emp_id' => (string) $p->emp_id,
                'name' => $this->fullName($p),
                'department' => $p->latestEmployment?->department?->department_name,
            ])->values()->all(),
            'remarks' => $t->remarks,
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

        return $name !== '' ? $name : 'Unknown';
    }
}
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TripTicket;
use App\Models\Vehicle;
use App\Services\TripScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company vehicles used by trip tickets.
 *
 * Status: active (can be assigned), maintenance (shown but not assignable),
 * inactive (hidden from trip tickets, e.g. sold).
 */
class VehicleController extends Controller
{
    private const STATUSES = ['active', 'maintenance', 'inactive'];

    public function __construct(private readonly TripScheduleService $schedule)
    {
    }

    /**
     * GET /admin/vehicles?search=&status=
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $vehicles = Vehicle::query()
            ->withCount('tripTickets')
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $like = '%'.$search.'%';
                $w->where('plate_no', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('vehicle_type', 'like', $like);
            }))
            ->orderBy('plate_no')
            ->get();

        $odometer = $this->schedule->lastOdometerByVehicle();
        $onTrip = $this->currentTrips();
        $upcoming = $this->upcomingTripCounts();

        return response()->json([
            'success' => true,
            'message' => 'Vehicles retrieved.',
            'data' => $vehicles
                ->map(fn (Vehicle $v): array => $this->format($v, $odometer, $onTrip, $upcoming))
                ->values(),
            'stats' => [
                'total' => Vehicle::query()->count(),
                'active' => Vehicle::query()->where('status', 'active')->count(),
                'maintenance' => Vehicle::query()->where('status', 'maintenance')->count(),
                'inactive' => Vehicle::query()->where('status', 'inactive')->count(),
                'on_trip' => $onTrip->count(),
            ],
        ]);
    }

    /**
     * POST /admin/vehicles
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateVehicle($request);

        $vehicle = Vehicle::create($validated);

        return $this->respond($vehicle, "Vehicle {$vehicle->plate_no} added.", 201);
    }

    /**
     * PUT /admin/vehicles/{vehicle}
     */
    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $this->validateVehicle($request, $vehicle);

        if ($validated['status'] !== 'active' && $vehicle->status === 'active') {
            $this->assertNoPendingTrips($vehicle, $validated['status']);
        }

        $vehicle->update($validated);

        return $this->respond($vehicle, "Vehicle {$vehicle->plate_no} updated.");
    }

    /**
     * DELETE /admin/vehicles/{vehicle}
     * Only for vehicles that were never used. Otherwise set them inactive
     * so trip history keeps its plate numbers.
     */
    public function destroy(Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->tripTickets()->exists()) {
            throw ValidationException::withMessages([
                'vehicle' => 'This vehicle has trip history. Set it to Inactive instead of deleting it.',
            ]);
        }

        $plate = $vehicle->plate_no;
        $vehicle->delete();

        return response()->json([
            'success' => true,
            'message' => "Vehicle {$plate} deleted.",
            'data' => null,
        ]);
    }

    // =====================================================
    // HELPERS
    // =====================================================

    private function validateVehicle(Request $request, ?Vehicle $vehicle = null): array
    {
        $request->merge([
            'plate_no' => strtoupper(trim((string) $request->input('plate_no'))),
        ]);

        return $request->validate([
            'plate_no' => [
                'required', 'string', 'max:20',
                Rule::unique('vehicles', 'plate_no')->ignore($vehicle?->id),
            ],
            'description' => ['required', 'string', 'max:255'],
            'vehicle_type' => ['nullable', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ], [
            'plate_no.unique' => 'This plate number is already registered.',
            'capacity.min' => 'Capacity must be at least 1 passenger.',
        ]);
    }

    private function assertNoPendingTrips(Vehicle $vehicle, string $newStatus): void
    {
        $pending = TripTicket::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', ['scheduled', 'ongoing'])
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->orderBy('trip_date')
            ->pluck('ticket_no');

        if ($pending->isNotEmpty()) {
            $label = $newStatus === 'maintenance' ? 'under maintenance' : 'inactive';

            throw ValidationException::withMessages([
                'status' => "Cannot set the vehicle {$label} while it has upcoming trips: "
                    .$pending->implode(', ').'. Edit or cancel them first.',
            ]);
        }
    }

    /**
     * Ongoing trips keyed by vehicle id.
     *
     * @return Collection<int, string>
     */
    private function currentTrips(): Collection
    {
        return TripTicket::query()
            ->where('status', 'ongoing')
            ->pluck('ticket_no', 'vehicle_id');
    }

    /**
     * Scheduled trips from today onward, per vehicle.
     *
     * @return Collection<int, int>
     */
    private function upcomingTripCounts(): Collection
    {
        return TripTicket::query()
            ->where('status', 'scheduled')
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->selectRaw('vehicle_id, COUNT(*) AS total')
            ->groupBy('vehicle_id')
            ->pluck('total', 'vehicle_id')
            ->map(fn ($n) => (int) $n);
    }

    private function format(Vehicle $v, Collection $odometer, Collection $onTrip, Collection $upcoming): array
    {
        $reading = $odometer->get($v->id);

        return [
            'id' => $v->id,
            'plate_no' => $v->plate_no,
            'description' => $v->description,
            'vehicle_type' => $v->vehicle_type,
            'capacity' => $v->capacity,
            'status' => $v->status,
            'trips_count' => (int) ($v->trip_tickets_count ?? 0),
            'upcoming_trips' => $upcoming->get($v->id, 0),
            'current_trip' => $onTrip->get($v->id),
            'last_odometer' => $reading && $reading > 0 ? $reading : null,
        ];
    }

    private function respond(Vehicle $vehicle, string $message, int $status = 200): JsonResponse
    {
        $vehicle->loadCount('tripTickets');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->format(
                $vehicle,
                $this->schedule->lastOdometerByVehicle(),
                $this->currentTrips(),
                $this->upcomingTripCounts(),
            ),
        ], $status);
    }
}
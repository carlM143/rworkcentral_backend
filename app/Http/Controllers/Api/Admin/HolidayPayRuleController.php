<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\HolidayPayRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Models\HolidayType;

class HolidayPayRuleController extends Controller
{
    private const RELATIONS = ['holidayType:id,type_code,type_name'];

    private function rules(): array
    {
        return [
            'holiday_type_id' => ['required', 'integer', 'exists:holiday_types,id'],
            'scenario' => ['required', Rule::in(HolidayPayRule::SCENARIOS)],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'applies_to' => ['nullable', Rule::in(HolidayPayRule::APPLIES_TO)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_status' => ['nullable', 'boolean'],
        ];
    }

    public function types(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Holiday types retrieved successfully.',
            'data' => HolidayType::query()
                ->where('status', true)
                ->orderBy('id')
                ->get(['id', 'type_code', 'type_name']),
        ]);
    }

    /**
     * Only one active rule may apply to the same holiday type, scenario and
     * salary group on any date, so payroll never finds two different rates.
     */
    private function guardOverlap(array $data, ?int $ignoreId = null): void
    {
        if (!($data['is_status'] ?? true)) {
            return;
        }

        $from = $data['effective_from'];
        $to = $data['effective_to'] ?? null;

        $overlap = HolidayPayRule::query()
            ->where('holiday_type_id', $data['holiday_type_id'])
            ->where('scenario', $data['scenario'])
            ->where('applies_to', $data['applies_to'] ?? 'all')
            ->where('is_status', true)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->when($to, fn ($q) => $q->whereDate('effective_from', '<=', $to))
            ->where(fn ($q) => $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $from))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'effective_from' => 'An active rule for this holiday type and scenario already covers these dates. Set an Effective To on the existing rule first.',
            ]);
        }
    }

    // GET /api/admin/holiday-pay-rules?holiday_type_id=&scenario=&status=
    public function index(Request $request): JsonResponse
    {
        $rules = HolidayPayRule::query()
            ->with(self::RELATIONS)
            ->when($request->filled('holiday_type_id'), fn ($q) => $q->where('holiday_type_id', $request->integer('holiday_type_id')))
            ->when($request->filled('scenario'), fn ($q) => $q->where('scenario', $request->string('scenario')->toString()))
            ->when($request->filled('status'), function ($q) use ($request) {
                $status = strtolower($request->string('status')->toString());
                $q->where('is_status', in_array($status, ['active', '1', 'true'], true));
            })
            ->orderBy('holiday_type_id')
            ->orderByRaw("FIELD(scenario, 'unworked', 'worked', 'worked_overtime', 'rest_day_worked', 'rest_day_overtime')")
            ->orderByDesc('effective_from')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Holiday pay rules retrieved successfully.',
            'data' => $rules,
        ]);
    }

    // POST /api/admin/holiday-pay-rules
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['applies_to'] = $data['applies_to'] ?? 'all';
        $data['is_status'] = $data['is_status'] ?? true;

        $this->guardOverlap($data);

        $rule = HolidayPayRule::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Holiday pay rule created successfully.',
            'data' => $rule->load(self::RELATIONS),
        ], 201);
    }

    // GET /api/admin/holiday-pay-rules/{holidayPayRule}
    public function show(HolidayPayRule $holidayPayRule): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Holiday pay rule retrieved successfully.',
            'data' => $holidayPayRule->load(self::RELATIONS),
        ]);
    }

    // PUT /api/admin/holiday-pay-rules/{holidayPayRule}
    public function update(Request $request, HolidayPayRule $holidayPayRule): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['applies_to'] = $data['applies_to'] ?? 'all';
        $data['is_status'] = $data['is_status'] ?? $holidayPayRule->is_status;

        // A rule already used by payroll keeps its rate; only dates and status may change.
        if ($holidayPayRule->payrollHolidayPays()->exists()) {
            $locked = ['holiday_type_id', 'scenario', 'applies_to', 'rate_percent'];

            foreach ($locked as $field) {
                if ((string) $data[$field] !== (string) $holidayPayRule->{$field}) {
                    throw ValidationException::withMessages([
                        $field => 'This rule is already used in payroll. Set its Effective To and add a new rule for the new rate.',
                    ]);
                }
            }
        }

        $this->guardOverlap($data, $holidayPayRule->id);

        $holidayPayRule->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Holiday pay rule updated successfully.',
            'data' => $holidayPayRule->fresh()->load(self::RELATIONS),
        ]);
    }

    // DELETE /api/admin/holiday-pay-rules/{holidayPayRule}
    public function destroy(HolidayPayRule $holidayPayRule): JsonResponse
    {
        if ($holidayPayRule->payrollHolidayPays()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This rule is already used in payroll and cannot be deleted. Set it to inactive instead.',
            ], 409);
        }

        $holidayPayRule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Holiday pay rule deleted successfully.',
        ]);
    }
}
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PayrollPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayrollPeriodController extends Controller
{
    private const STATUS_FLOW = [
        'draft' => ['open'],
        'open' => ['draft', 'processing'],
        'processing' => ['open', 'closed'],
        'closed' => [],
    ];

    private function companyId(Request $request): int
    {
        abort_unless($request->user(), 401, 'Unauthenticated.');

        if ($request->user()->company_id) {
            return (int) $request->user()->company_id;
        }

        // RWorkCentral is currently a single-company HRIS.
        return (int) Company::query()->sole()->id;
    }

    private function authorizePeriod(Request $request, PayrollPeriod $payrollPeriod): void
    {
        abort_unless(
            (int) $payrollPeriod->company_id === $this->companyId($request),
            404,
            'Payroll period not found.'
        );
    }

    private function rules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'period_name' => ['required', 'string', 'max:255'],
            'cutoff_number' => ['required', 'integer', 'in:1,2'],
            'pay_period_start' => [
                'required',
                'date',
                Rule::unique('payroll_periods', 'pay_period_start')
                    ->where('company_id', $companyId)
                    ->ignore($ignoreId),
            ],
            'pay_period_end' => ['required', 'date', 'after_or_equal:pay_period_start'],
            'cutoff_start' => ['required', 'date'],
            'cutoff_end' => ['required', 'date', 'after_or_equal:cutoff_start'],
            'payment_date' => ['required', 'date', 'after_or_equal:cutoff_end'],
        ];
    }

    private function messages(): array
    {
        return [
            'pay_period_start.unique' => 'A payroll period with this start date already exists.',
            'pay_period_end.after_or_equal' => 'Payroll period end must be on or after its start.',
            'cutoff_end.after_or_equal' => 'Cut-off end must be on or after the cut-off start.',
            'payment_date.after_or_equal' => 'Payment date must be on or after the cut-off end.',
        ];
    }

    private function format(PayrollPeriod $period): array
    {
        return [
            'id' => $period->id,
            'user_id' => $period->user_id,
            'company_id' => $period->company_id,
            'label' => $period->period_name ?? "Payroll Period #{$period->id}",
            'period_name' => $period->period_name,
            'cutoff_number' => (int) $period->cutoff_number,
            'pay_period_start' => $period->pay_period_start?->format('Y-m-d'),
            'pay_period_end' => $period->pay_period_end?->format('Y-m-d'),
            'cutoff_start' => $period->cutoff_start?->format('Y-m-d'),
            'cutoff_end' => $period->cutoff_end?->format('Y-m-d'),
            'payment_date' => $period->payment_date?->format('Y-m-d'),
            'status' => $period->status,
        ];
    }

    // GET /api/admin/payroll-periods
    public function index(Request $request): JsonResponse
    {
        $periods = PayrollPeriod::query()
            ->where('company_id', $this->companyId($request))
            ->orderByDesc('pay_period_start')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PayrollPeriod $period) => $this->format($period));

        return response()->json([
            'success' => true,
            'message' => 'Payroll periods retrieved successfully.',
            'data' => $periods,
        ]);
    }

    // POST /api/admin/payroll-periods
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $data = $request->validate($this->rules($companyId), $this->messages());

        $payrollPeriod = PayrollPeriod::create([
            ...$data,
            'user_id' => $request->user()->id,
            'company_id' => $companyId,
            'status' => 'draft',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payroll period created successfully.',
            'data' => $this->format($payrollPeriod->fresh()),
        ], 201);
    }

    // GET /api/admin/payroll-periods/{payrollPeriod}
    public function show(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorizePeriod($request, $payrollPeriod);

        return response()->json([
            'success' => true,
            'message' => 'Payroll period retrieved successfully.',
            'data' => $this->format($payrollPeriod),
        ]);
    }

    // PUT/PATCH /api/admin/payroll-periods/{payrollPeriod}
    public function update(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorizePeriod($request, $payrollPeriod);

        if ($payrollPeriod->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Only draft payroll periods can be edited.',
            ]);
        }

        $data = $request->validate(
            $this->rules((int) $payrollPeriod->company_id, $payrollPeriod->id),
            $this->messages()
        );

        $payrollPeriod->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Payroll period updated successfully.',
            'data' => $this->format($payrollPeriod->fresh()),
        ]);
    }

    // PATCH /api/admin/payroll-periods/{payrollPeriod}/status
    public function updateStatus(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorizePeriod($request, $payrollPeriod);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(self::STATUS_FLOW))],
        ]);

        $current = strtolower((string) $payrollPeriod->status);
        $next = $data['status'];

        if (!in_array($next, self::STATUS_FLOW[$current] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "A {$current} payroll period cannot be changed to {$next}.",
            ]);
        }

        if ($next === 'draft' && $payrollPeriod->payrolls()->exists()) {
            throw ValidationException::withMessages([
                'status' => 'This period already has payroll records and cannot go back to draft.',
            ]);
        }

        $payrollPeriod->update(['status' => $next]);

        return response()->json([
            'success' => true,
            'message' => 'Payroll period is now ' . $next . '.',
            'data' => $this->format($payrollPeriod->fresh()),
        ]);
    }

    // DELETE /api/admin/payroll-periods/{payrollPeriod}
    public function destroy(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorizePeriod($request, $payrollPeriod);

        if ($payrollPeriod->payrolls()->exists()) {
            throw ValidationException::withMessages([
                'payroll_period' => 'This payroll period already has payroll records and cannot be deleted.',
            ]);
        }

        if ($payrollPeriod->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Only draft payroll periods can be deleted.',
            ]);
        }

        $payrollPeriod->delete();

        return response()->json([
            'success' => true,
            'message' => 'Payroll period deleted successfully.',
        ]);
    }
}
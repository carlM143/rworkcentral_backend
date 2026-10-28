<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PayrollPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayrollPeriodController extends Controller
{
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

    private function rules(): array
    {
        return [
            'period_name' => ['required', 'string', 'max:255'],
            'cutoff_start' => ['required', 'date'],
            'cutoff_end' => ['required', 'date', 'after_or_equal:cutoff_start'],
            'payment_date' => ['required', 'date', 'after_or_equal:cutoff_end'],
        ];
    }

    // GET /api/admin/payroll-periods
    public function index(Request $request): JsonResponse
    {
        $periods = PayrollPeriod::query()
            ->where('company_id', $this->companyId($request))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($period) => [
                'id' => $period->id,
                'user_id' => $period->user_id,
                'company_id' => $period->company_id,
                'label' => $period->period_name ?? "Payroll Period #{$period->id}",
                'period_name' => $period->period_name,
                'cutoff_start' => $period->cutoff_start?->format('Y-m-d'),
                'cutoff_end' => $period->cutoff_end?->format('Y-m-d'),
                'payment_date' => $period->payment_date?->format('Y-m-d'),
                'status' => $period->status,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Payroll periods retrieved successfully.',
            'data' => $periods,
        ]);
    }

    // POST /api/admin/payroll-periods
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $payrollPeriod = PayrollPeriod::create([
            ...$data,
            'user_id' => $request->user()->id,
            'company_id' => $this->companyId($request),
            'status' => 'Draft',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payroll period created successfully.',
            'data' => $payrollPeriod,
        ], 201);
    }

    // GET /api/admin/payroll-periods/{payrollPeriod}
    public function show(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorizePeriod($request, $payrollPeriod);

        return response()->json([
            'success' => true,
            'message' => 'Payroll period retrieved successfully.',
            'data' => $payrollPeriod->load(['user', 'company']),
        ]);
    }

    // PATCH /api/admin/payroll-periods/{payrollPeriod}
    public function update(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorizePeriod($request, $payrollPeriod);

        $data = $request->validate($this->rules());

        if (strtolower(trim((string) $payrollPeriod->status)) !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Only draft payroll periods can be edited.',
            ]);
        }

        $payrollPeriod->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Payroll period updated successfully.',
            'data' => $payrollPeriod->fresh(),
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

        if (strtolower(trim((string) $payrollPeriod->status)) !== 'draft') {
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

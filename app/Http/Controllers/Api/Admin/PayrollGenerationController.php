<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Services\PayrollGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayrollGenerationController extends Controller
{
    public function __construct(
        private readonly PayrollGenerationService $service
    ) {
    }

    private function companyId(Request $request): int
    {
        abort_unless($request->user(), 401, 'Unauthenticated.');

        if ($request->user()->company_id) {
            return (int) $request->user()->company_id;
        }

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

    // GET /api/admin/payroll-generation/{payrollPeriod}
    public function index(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorizePeriod($request, $payrollPeriod);

        $payrolls = Payroll::query()
            ->where('payroll_period_id', $payrollPeriod->id)
            ->with([
                'employee:id,emp_id,firstname,lastname,department_id',
                'payrollGroup:id,group_name,frequency',
                'salaryType:id,type_name',
                'contributions.contributionType:id,contribution_code,contribution_name',
            ])
            ->get()
            ->sortBy(fn ($p) => "{$p->employee?->lastname} {$p->employee?->firstname}")
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Payroll records retrieved successfully.',
            'data' => [
                'payrolls' => $payrolls,
                'totals' => [
                    'employees' => $payrolls->count(),
                    'draft' => $payrolls->where('status', 'draft')->count(),
                    'finalized' => $payrolls->where('status', 'finalized')->count(),
                    'gross_pay' => round($payrolls->sum('gross_pay'), 2),
                    'total_deductions' => round($payrolls->sum('total_deductions'), 2),
                    'net_pay' => round($payrolls->sum('net_pay'), 2),
                ],
            ],
        ]);
    }

    // POST /api/admin/payroll-generation/{payrollPeriod}/generate
    public function generate(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorizePeriod($request, $payrollPeriod);

        $data = $request->validate([
            'payroll_group_id' => ['nullable', 'integer', 'exists:payroll_groups,id'],
        ]);

        $status = strtolower((string) $payrollPeriod->status);

        if (!in_array($status, ['open', 'processing'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Open the payroll period before generating payroll.',
            ]);
        }

        $result = $this->service->generate(
            $payrollPeriod,
            isset($data['payroll_group_id']) ? (int) $data['payroll_group_id'] : null,
            $request->user()?->id
        );

        if ($status === 'open' && count($result['generated']) > 0) {
            $payrollPeriod->update(['status' => 'processing']);
        }

        $generated = count($result['generated']);
        $skipped = count($result['skipped']);

        $message = $generated
            ? "Payroll generated for {$generated} employee(s)."
            : 'No payroll records were generated.';

        if ($skipped) {
            $message .= " {$skipped} skipped.";
        }

        return response()->json([
            'success' => $generated > 0,
            'message' => $message,
            'data' => $result,
        ], $generated > 0 ? 201 : 200);
    }

    // POST /api/admin/payroll-generation/{payrollPeriod}/finalize
    public function finalize(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorizePeriod($request, $payrollPeriod);

        if (strtolower((string) $payrollPeriod->status) !== 'processing') {
            throw ValidationException::withMessages([
                'status' => 'Only a processing payroll period can be finalized.',
            ]);
        }

        if (!$payrollPeriod->payrolls()->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages([
                'payroll' => 'There are no draft payroll records to finalize.',
            ]);
        }

        $result = $this->service->finalize($payrollPeriod, $request->user()?->id);

        $payrollPeriod->update(['status' => 'closed']);

        return response()->json([
            'success' => true,
            'message' => "{$result['finalized']} payroll record(s) finalized. The period is now closed.",
            'data' => $result,
        ]);
    }
}
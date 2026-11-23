<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /admin/dashboard/payroll-status
 *
 * The payroll cutoff that needs attention now, with its progress:
 *   Draft -> Open -> Generated (processing) -> Finalized (closed) -> Paid
 * "Paid" is reached when a closed period's payment date has arrived.
 *
 * Which period is shown:
 *   1. the earliest open or processing period (work in progress), else
 *   2. the earliest draft period, else
 *   3. the most recent closed period.
 */
class DashboardPayrollController extends Controller
{
    private const STEPS = ['Draft', 'Open', 'Generated', 'Finalized', 'Paid'];

    public function show(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $periods = DB::table('payroll_periods')
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled');

        $period = (clone $periods)->whereIn('status', ['open', 'processing'])->orderBy('pay_period_start')->first()
            ?? (clone $periods)->where('status', 'draft')->orderBy('pay_period_start')->first()
            ?? (clone $periods)->where('status', 'closed')->orderByDesc('pay_period_start')->first();

        if (! $period) {
            return response()->json([
                'success' => true,
                'message' => 'No payroll period yet.',
                'data' => null,
            ]);
        }

        $payday = Carbon::parse($period->payment_date)->startOfDay();
        $paid = $period->status === 'closed' && $payday->lte(today());

        $step = match (true) {
            $paid => 4,
            $period->status === 'closed' => 3,
            $period->status === 'processing' => 2,
            $period->status === 'open' => 1,
            default => 0,
        };

        $totals = DB::table('payrolls')
            ->where('payroll_period_id', $period->id)
            ->selectRaw("
                COUNT(*) AS generated_count,
                SUM(CASE WHEN status = 'finalized' THEN 1 ELSE 0 END) AS finalized_count,
                COALESCE(SUM(gross_pay), 0) AS gross,
                COALESCE(SUM(total_deductions), 0) AS deductions,
                COALESCE(SUM(net_pay), 0) AS net
            ")
            ->first();

        $activeEmployees = DB::table('employees')->where('status', true)->count();

        // Last finished cutoff, to compare the net pay.
        $previous = (clone $periods)
            ->where('status', 'closed')
            ->where('id', '!=', $period->id)
            ->where('pay_period_start', '<', $period->pay_period_start)
            ->orderByDesc('pay_period_start')
            ->first();

        $previousNet = $previous
            ? (float) DB::table('payrolls')->where('payroll_period_id', $previous->id)->sum('net_pay')
            : null;

        return response()->json([
            'success' => true,
            'message' => 'Payroll status retrieved.',
            'data' => [
                'period' => [
                    'id' => $period->id,
                    'name' => $period->period_name,
                    'cutoff_start' => $period->cutoff_start,
                    'cutoff_end' => $period->cutoff_end,
                    'payment_date' => $period->payment_date,
                    'status' => $period->status,
                    'days_to_payday' => (int) today()->diffInDays($payday, false),
                ],
                'status_label' => self::STEPS[$step],
                'step' => $step,
                'steps' => self::STEPS,
                'next_action' => $this->nextAction($period->status, $paid, (int) $totals->generated_count),
                'employees' => [
                    'active' => $activeEmployees,
                    'generated' => (int) $totals->generated_count,
                    'finalized' => (int) $totals->finalized_count,
                ],
                'totals' => [
                    'gross' => round((float) $totals->gross, 2),
                    'deductions' => round((float) $totals->deductions, 2),
                    'net' => round((float) $totals->net, 2),
                ],
                'previous' => $previous ? [
                    'name' => $previous->period_name,
                    'net' => round($previousNet, 2),
                ] : null,
            ],
        ]);
    }

    /**
     * What HR should do next, and where.
     */
    private function nextAction(string $status, bool $paid, int $generated): array
    {
        return match (true) {
            $status === 'draft' => ['label' => 'Open the payroll period', 'url' => '/admin/payroll/payroll-period'],
            $status === 'open' && $generated === 0 => ['label' => 'Check readiness and generate payroll', 'url' => '/admin/payroll/payroll-readiness'],
            $status === 'open' => ['label' => 'Review the generated payroll', 'url' => '/admin/employee-payroll'],
            $status === 'processing' => ['label' => 'Review and finalize payroll', 'url' => '/admin/employee-payroll'],
            $status === 'closed' && ! $paid => ['label' => 'Release payslips on payday', 'url' => '/admin/payroll-payslips'],
            default => ['label' => 'View payroll history', 'url' => '/admin/payroll-history'],
        };
    }

    private function companyId(Request $request): int
    {
        abort_unless($request->user(), 401, 'Unauthenticated.');

        if ($request->user()->company_id) {
            return (int) $request->user()->company_id;
        }

        return (int) Company::query()->sole()->id;
    }
}
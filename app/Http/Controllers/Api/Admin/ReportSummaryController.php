<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reports › Reports Center: a one-line status for each report card.
 *
 *   GET /admin/reports/summary
 *
 * Only summaries the user may see are computed (privileges of the reports
 * module). Each part fails quietly so one broken report never blocks the page.
 */
class ReportSummaryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $can = fn (string $name) => $user->hasPrivilege('reports', $name);

        $data = [];

        if ($can('attendance.view')) {
            $data['attendance'] = $this->safe(fn () => $this->attendance());
        }
        if ($can('payroll.view')) {
            $data['payroll'] = $this->safe(fn () => $this->payroll());
        }
        if ($can('government.view')) {
            $data['government'] = $this->safe(fn () => $this->government());
        }
        if ($can('thirteenth_month.view')) {
            $data['thirteenth_month'] = $this->safe(fn () => $this->thirteenthMonth());
        }
        if ($can('withholding_tax.view')) {
            $data['withholding_tax'] = $this->safe(fn () => $this->withholdingTax());
        }
        if ($can('wage_compliance.view')) {
            $data['wage_compliance'] = $this->safe(fn () => $this->wageCompliance($request));
        }

        return response()->json([
            'success' => true,
            'message' => 'Report summaries retrieved.',
            'data' => $data,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function attendance(): array
    {
        $start = today()->startOfMonth()->toDateString();
        $logs = DB::table('time_logs')->whereBetween('work_date', [$start, today()->toDateString()])->count();

        return [
            'headline' => today()->format('F Y'),
            'detail' => number_format($logs).' time log(s) so far this month',
            'state' => 'info',
        ];
    }

    private function payroll(): array
    {
        $period = DB::table('payroll_periods')
            ->join('payrolls', 'payrolls.payroll_period_id', '=', 'payroll_periods.id')
            ->where('payroll_periods.status', '!=', 'cancelled')
            ->groupBy('payroll_periods.id', 'payroll_periods.period_name', 'payroll_periods.pay_period_start')
            ->orderByDesc('payroll_periods.pay_period_start')
            ->selectRaw('payroll_periods.period_name, COUNT(payrolls.id) AS total, SUM(CASE WHEN payrolls.status = ? THEN 1 ELSE 0 END) AS finalized_total, SUM(payrolls.net_pay) AS net', ['finalized'])
            ->first();

        if (! $period) {
            return ['headline' => 'No payroll yet', 'detail' => 'Generate payroll in Payroll › Employee Payroll.', 'state' => 'empty'];
        }

        $official = (int) $period->finalized_total === (int) $period->total;

        return [
            'headline' => $period->period_name,
            'detail' => "{$period->total} payroll · net ₱".number_format((float) $period->net, 2).($official ? ' · Finalized' : ' · Preview'),
            'state' => $official ? 'ok' : 'warn',
        ];
    }

    private function government(): array
    {
        $month = DB::table('payroll_contributions')->max('contribution_month');

        if (! $month) {
            return ['headline' => 'No contributions yet', 'detail' => 'Contributions are saved when payroll is generated.', 'state' => 'empty'];
        }

        $start = Carbon::parse($month)->startOfMonth();
        $total = DB::table('payroll_contributions')
            ->whereBetween('contribution_month', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->selectRaw('SUM(employee_share + employer_share + employer_ec_amount) AS t')
            ->value('t');

        return [
            'headline' => $start->format('F Y'),
            'detail' => '₱'.number_format((float) $total, 2).' to remit (SSS, PhilHealth, Pag-IBIG)',
            'state' => 'info',
        ];
    }

    private function thirteenthMonth(): array
    {
        $year = today()->year;
        $earned = DB::table('payrolls')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
            ->whereYear('payroll_periods.cutoff_end', $year)
            ->where('payroll_periods.status', '!=', 'cancelled')
            ->selectRaw('SUM(GREATEST(payrolls.basic_pay - payrolls.late_deduction - payrolls.undertime_deduction - payrolls.unpaid_leave_deduction - payrolls.unpaid_holiday_deduction, 0)) AS earned')
            ->value('earned');

        $deadline = Carbon::create($year, 12, 24);
        $days = today()->diffInDays($deadline, false);

        return [
            'headline' => '₱'.number_format(((float) $earned) / 12, 2).' accrued in '.$year,
            'detail' => $days >= 0 ? "Pay by Dec 24 · {$days} day(s) left" : 'Deadline (Dec 24) has passed',
            'state' => $days >= 0 && $days <= 30 ? 'warn' : 'info',
        ];
    }

    private function withholdingTax(): array
    {
        $latest = DB::table('payrolls')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
            ->max('payroll_periods.cutoff_end');

        if (! $latest) {
            return ['headline' => 'No payroll yet', 'detail' => 'Tax is computed when payroll is generated.', 'state' => 'empty'];
        }

        $month = Carbon::parse($latest)->startOfMonth();
        $tax = DB::table('payrolls')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
            ->whereBetween('payroll_periods.cutoff_end', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->sum('payrolls.withholding_tax');

        return [
            'headline' => $month->format('F Y'),
            'detail' => '₱'.number_format((float) $tax, 2).' withheld · file BIR 1601-C by the 10th of next month',
            'state' => 'info',
        ];
    }

    private function wageCompliance(Request $request): array
    {
        $response = app(WageComplianceController::class)->index(Request::create('/', 'GET'));
        $stats = $response->getData(true)['stats'] ?? [];
        $below = (int) ($stats['below'] ?? 0);
        $pending = (int) ($stats['pending'] ?? 0);

        return [
            'headline' => $below > 0 ? "{$below} below minimum" : 'No one below minimum',
            'detail' => "{$stats['compliant']} compliant · {$pending} pending adjustment · {$stats['distortion']} distortion review",
            'state' => $below > 0 ? 'danger' : ($pending > 0 ? 'warn' : 'ok'),
        ];
    }

    private function safe(callable $fn): array
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return ['headline' => 'Unavailable', 'detail' => 'Open the report for details.', 'state' => 'empty'];
        }
    }
}

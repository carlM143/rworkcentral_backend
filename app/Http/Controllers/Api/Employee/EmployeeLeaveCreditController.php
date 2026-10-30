<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Employment;
use App\Models\EmploymentType;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeLeaveCreditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');

        $employee = Employee::where('user_id', $user->id)->first();

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 404);
        }

        $employment = Employment::where('employee_id', $employee->id)
            ->orderByDesc('date_hired')
            ->orderByDesc('id')
            ->first();

        if (!$employment) {
            return response()->json([
                'success' => false,
                'message' => 'Employee employment information not found.',
            ], 404);
        }

        $employmentType = EmploymentType::find(
            $employment->employment_type_id
        );

        if (!$employmentType) {
            return response()->json([
                'success' => false,
                'message' => 'Employment type not found.',
            ], 404);
        }

        $today = Carbon::today();
        $year = (int) $today->year;

        $cycleStart = "{$year}-01-01";
        $cycleEnd = "{$year}-12-31";

        $dateHired = Carbon::parse($employment->date_hired);

        $monthsOfService = max(
            0,
            (int) $dateHired->diffInMonths($today)
        );

        $leaveTypes = LeaveType::query()
            ->where('status', 'active')
            ->orderBy('leave_name')
            ->get();

        $policies = LeavePolicy::query()
            ->where('employment_type_id', $employmentType->id)
            ->whereIn('leave_type_id', $leaveTypes->pluck('id'))
            ->where('status', 'approved')
            ->where(function ($query) use ($today) {
                $query->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $today);
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $today);
            })
            ->get();

        $rows = [];

        foreach ($leaveTypes as $leaveType) {
            $matchingPolicies = $policies
                ->where('leave_type_id', $leaveType->id);

            if ($matchingPolicies->isEmpty()) {
                $rows[] = [
                    'leave_type_id' => $leaveType->id,
                    'leave_code' => $leaveType->leave_code,
                    'leave_type' => $leaveType->leave_name,
                    'employment_type' => $employmentType->name,
                    'employment_type_id' => $employmentType->id,
                    'leave_policy_id' => null,
                    'annual_credits' => 0,
                    'available_credits' => 0,
                    'pay' => 'N/A',
                    'min_service_months' => 0,
                    'status' => 'no_policy',
                ];

                continue;
            }

            $groups = $matchingPolicies->groupBy(
                fn ($policy) => $policy->is_paid ? 'paid' : 'unpaid'
            );

            foreach ($groups as $payType => $group) {
                $qualifiedPolicy = $group
                    ->filter(
                        fn ($policy) =>
                            (int) $policy->min_service_months <= $monthsOfService
                    )
                    ->sortByDesc('min_service_months')
                    ->first();

                $policy = $qualifiedPolicy
                    ?? $group->sortBy('min_service_months')->first();

                if (!$policy) {
                    continue;
                }

                $status = !$qualifiedPolicy
                    ? 'not_yet_eligible'
                    : (!$policy->is_eligible ? 'not_eligible' : 'eligible');

                
                $annualCredits = $status === 'eligible'
                    ? round((float) $policy->annual_credits, 2)
                    : 0;

                $availableCredits = 0;

                if ($status === 'eligible' && $payType === 'paid') {

                    // Check transactions for this employee,
                    // leave type, employment type, and leave year.
                    $ledger = DB::table('leave_credit_transactions as t')
                        ->join(
                            'leave_policies as p',
                            'p.id',
                            '=',
                            't.leave_policy_id'
                        )
                        ->where('t.employee_id', $employee->id)
                        ->where('p.leave_type_id', $leaveType->id)
                        ->where('p.employment_type_id', $employmentType->id)
                        ->where('p.is_paid', true)
                        ->where('t.cycle_start', $cycleStart)
                        ->where('t.cycle_end', $cycleEnd)
                        ->selectRaw('COUNT(t.id) as transaction_count')
                        ->selectRaw('COALESCE(SUM(t.days_delta), 0) as balance')
                        ->first();

                    $hasTransactions = (int) ($ledger->transaction_count ?? 0) > 0;

                    if ($hasTransactions) {

                        // Transactions exist: use the actual ledger balance.
                        $availableCredits = round(
                            (float) $ledger->balance,
                            2
                        );

                    } else {

                        // No transactions: display the policy allowance.
                        $availableCredits = $annualCredits;

                    }
                }


                $rows[] = [
                    'leave_type_id' => $leaveType->id,
                    'leave_code' => $leaveType->leave_code,
                    'leave_type' => $leaveType->leave_name,
                    'employment_type' => $employmentType->name,
                    'employment_type_id' => $employmentType->id,
                    'leave_policy_id' => $policy->id,
                    'annual_credits' => $annualCredits,
                    'available_credits' => $availableCredits,
                    'pay' => $payType === 'paid' ? 'Paid' : 'Unpaid',
                    'min_service_months' => (int) $policy->min_service_months,
                    'status' => $status,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Employee leave credits retrieved successfully.',
            'data' => [
                'employee_id' => $employee->id,
                'emp_id' => $employee->emp_id,
                'employee_name' => trim(
                    $employee->firstname . ' ' . $employee->lastname
                ),
                'employment_type' => $employmentType->name,
                'employment_type_id' => $employmentType->id,
                'date_hired' => $dateHired->format('Y-m-d'),
                'months_of_service' => $monthsOfService,
                'leave_year' => $year,
                'leave_types' => $rows,
            ],
        ]);
    }
}

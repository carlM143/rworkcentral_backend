<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeLoan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeLoanController extends Controller
{
    /** Transactions that reduce the balance. */
    private const PAYMENT_TYPES = ['payroll_deduction', 'manual_payment'];

    // GET /api/admin/employee-loans
    // Optional: ?search=&status=active|inactive|fully_paid&loan_type_id=&employee_id=&per_page=
    // Without per_page, `data` is the full list (as before). With per_page it is one page, plus `meta`.
    public function index(Request $request): JsonResponse
    {
        $this->authorizeLoans($request, 'view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'fully_paid'])],
            'loan_type_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = $this->withBalances(EmployeeLoan::query())
            ->with([
                'employee:id,emp_id,firstname,middlename,lastname',
                'loanType:id,loan_code,loan_name,provider',
                'createdBy:id,name',
            ])
            ->when($filters['loan_type_id'] ?? null, fn (Builder $q, $id) => $q->where('loan_type_id', $id))
            ->when($filters['employee_id'] ?? null, fn (Builder $q, $id) => $q->where('employee_id', $id))
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = '%' . trim($term) . '%';

                $q->where(fn (Builder $w) => $w
                    ->where('loan_reference_no', 'like', $like)
                    ->orWhereHas('employee', fn (Builder $e) => $e
                        ->where('emp_id', 'like', $like)
                        ->orWhere('firstname', 'like', $like)
                        ->orWhere('lastname', 'like', $like)));
            })
            ->when($filters['status'] ?? null, function (Builder $q, string $status) {
                $balance = $this->balanceSql();

                match ($status) {
                    'fully_paid' => $q->whereRaw("{$balance} <= 0"),
                    'active' => $q->where('status', true)->whereRaw("{$balance} > 0"),
                    'inactive' => $q->where('status', false)->whereRaw("{$balance} > 0"),
                };
            })
            ->latest('id');

        if (isset($filters['per_page'])) {
            $page = $query->paginate($filters['per_page']);

            return response()->json([
                'success' => true,
                'message' => 'Employee loans retrieved successfully.',
                'data' => collect($page->items())
                    ->map(fn (EmployeeLoan $loan) => $this->formatLoan($loan))
                    ->values(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Employee loans retrieved successfully.',
            'data' => $query->get()
                ->map(fn (EmployeeLoan $loan) => $this->formatLoan($loan))
                ->values(),
        ]);
    }

    public function show(Request $request, EmployeeLoan $employeeLoan): JsonResponse
    {
        $this->authorizeLoans($request, 'view');

        return response()->json([
            'success' => true,
            'message' => 'Employee loan retrieved successfully.',
            'data' => $this->formatLoan($this->reload($employeeLoan)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeLoans($request, 'create');

        $validated = $request->validate(
            $this->rules($request)
        );

        $validated['loan_reference_no'] = strtoupper(
            trim($validated['loan_reference_no'])
        );

        // Opening balance initially equals total payable.
        // Actual deductions will be recorded separately.
        $validated['opening_balance'] =
            $validated['total_payable_amount'];

        // Default: whole monthly deduction on the 1st cutoff.
        $validated['first_cutoff_percent'] ??= 100;
        $validated['second_cutoff_percent'] ??= 0;

        // Never trust created_by from the frontend.
        $validated['created_by'] = $request->user()->id;

        $loan = EmployeeLoan::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Employee loan created successfully.',
            'data' => $this->formatLoan($this->reload($loan)),
        ], 201);
    }

    public function update(
        Request $request,
        EmployeeLoan $employeeLoan
    ): JsonResponse {
        $this->authorizeLoans($request, 'edit');

        // Prevent changing financial terms once active transactions exist.
        // Voided transactions (status = false) do not count.
        if ($employeeLoan->transactions()->where('status', true)->exists()) {
            return response()->json([
                'success' => false,
                'message' =>
                    'This loan already has transactions. ' .
                    'Its financial terms cannot be edited through this form.',
            ], 422);
        }

        $validated = $request->validate(
            $this->rules($request, $employeeLoan)
        );

        $validated['loan_reference_no'] = strtoupper(
            trim($validated['loan_reference_no'])
        );

        $validated['opening_balance'] =
            $validated['total_payable_amount'];

        $employeeLoan->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Employee loan updated successfully.',
            'data' => $this->formatLoan($this->reload($employeeLoan)),
        ]);
    }

    // PATCH /api/admin/employee-loans/{employeeLoan}/deduction-schedule
    // body: { "first_cutoff_percent": 100, "second_cutoff_percent": 0 }
    // Sets which cutoff of the month the loan is deducted in. Allowed even after deductions started,
    // because it only affects payrolls generated from now on (finalized payrolls are not changed).
    public function updateDeductionSchedule(Request $request, EmployeeLoan $employeeLoan): JsonResponse
    {
        $this->authorizeLoans($request, 'edit');

        $validated = $request->validate([
            'first_cutoff_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'second_cutoff_percent' => ['required', 'numeric', 'min:0', 'max:100', $this->totalsHundred()],
        ]);

        $before = $employeeLoan->deductionScheduleLabel();

        $employeeLoan->update($validated);

        $after = $employeeLoan->deductionScheduleLabel();

        return response()->json([
            'success' => true,
            'message' => $before === $after
                ? "Deduction schedule unchanged ({$after})."
                : "Deduction schedule changed from {$before} to {$after}. It applies to payrolls generated from now on.",
            'data' => $this->formatLoan($this->reload($employeeLoan)),
        ]);
    }

    /** First + second cutoff percent must total 100, like contribution deduction settings. */
    private function totalsHundred(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $first = request()->input('first_cutoff_percent');

            if (is_numeric($first) && is_numeric($value)
                && abs(round((float) $first + (float) $value, 2) - 100.0) > 0.01) {
                $fail('First cutoff and second cutoff percentages must total 100%.');
            }
        };
    }

    /**
     * Uses the role privileges from Settings › Roles (config/privileges.php):
     * loans › employee_loans.view / .create / .edit. Administrators have every privilege.
     */
    private function authorizeLoans(Request $request, string $action): void
    {
        abort_unless(
            $request->user()?->hasPrivilege('loans', "employee_loans.{$action}"),
            403,
            "You are not allowed to {$action} employee loans."
        );
    }

    /** Current balance in SQL: opening balance + active transactions (same as payroll). */
    private function balanceSql(): string
    {
        return '(employee_loans.opening_balance + COALESCE((SELECT SUM(t.amount_delta) '
            . 'FROM employee_loan_transactions t '
            . 'WHERE t.employee_loan_id = employee_loans.id AND t.status = 1), 0))';
    }

    /** Adds active_delta (all active transactions) and paid_total (payments only). */
    private function withBalances(Builder $query): Builder
    {
        return $query->withSum(
            ['transactions as active_delta' => fn ($q) => $q->where('status', true)],
            'amount_delta'
        )->withSum(
            ['transactions as paid_delta' => fn ($q) => $q
                ->where('status', true)
                ->whereIn('transaction_type', self::PAYMENT_TYPES)],
            'amount_delta'
        );
    }

    private function reload(EmployeeLoan $loan): EmployeeLoan
    {
        return $this->withBalances(EmployeeLoan::query())
            ->with([
                'employee:id,emp_id,firstname,middlename,lastname',
                'loanType:id,loan_code,loan_name,provider',
                'createdBy:id,name',
            ])
            ->findOrFail($loan->id);
    }

    private function rules(
        Request $request,
        ?EmployeeLoan $loan = null
    ): array {
        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id'),
            ],

            'loan_type_id' => [
                'required',
                'integer',
                Rule::exists('loan_types', 'id'),
            ],

            'loan_reference_no' => [
                'required',
                'string',
                'max:100',
                Rule::unique(
                    'employee_loans',
                    'loan_reference_no'
                )->ignore($loan?->id),
            ],

            'principal_amount' => [
                'required', 'numeric', 'min:0.01',
            ],

            'total_payable_amount' => [
                'required', 'numeric', 'gte:principal_amount',
            ],

            // Monthly deduction; split between cutoffs by the percents below
            'deduction_amount' => [
                'required', 'numeric',
                'min:0.01',
                'lte:total_payable_amount',
            ],

            // Optional (default 100 / 0 = 1st cutoff only)
            'first_cutoff_percent' => [
                'required_with:second_cutoff_percent', 'numeric', 'min:0', 'max:100',
            ],

            'second_cutoff_percent' => [
                'required_with:first_cutoff_percent', 'numeric', 'min:0', 'max:100',
                $this->totalsHundred(),
            ],

            'loan_date' => [
                'required', 'date',
            ],

            'deduction_start_date' => [
                'required', 'date', 'after_or_equal:loan_date',
            ],

            'remarks' => [
                'nullable', 'string', 'max:2000',
            ],

            'status' => [
                'required', 'boolean',
            ],
        ];
    }

    private function formatLoan(EmployeeLoan $loan): array
    {
        $employee = $loan->employee;
        $type = $loan->loanType;

        $employeeName = $employee
            ? trim(implode(' ', array_filter([
                $employee->firstname,
                $employee->middlename,
                $employee->lastname,
            ])))
            : null;

        $balance = max(0, round((float) $loan->opening_balance + (float) ($loan->active_delta ?? 0), 2));
        $paid = round(abs((float) ($loan->paid_delta ?? 0)), 2);

        // status = false is set both by HR (deactivated) and by payroll when the loan is paid off,
        // so loan_state tells them apart using the balance.
        $state = match (true) {
            $balance <= 0 => 'fully_paid',
            ! $loan->status => 'inactive',
            default => 'active',
        };

        return [
            'id' => $loan->id,

            'employee_id' => $loan->employee_id,
            'loan_type_id' => $loan->loan_type_id,

            'loan_reference_no' => $loan->loan_reference_no,

            'employee' => $employee ? [
                'id' => $employee->id,
                'emp_id' => $employee->emp_id,
                'name' => $employeeName ?: 'Unnamed Employee',
            ] : null,

            'loan_type' => $type ? [
                'id' => $type->id,
                'loan_code' => $type->loan_code,
                'loan_name' => $type->loan_name,
                'provider' => $type->provider,
            ] : null,

            'principal_amount' => (float) $loan->principal_amount,
            'total_payable_amount' => (float) $loan->total_payable_amount,
            'opening_balance' => (float) $loan->opening_balance,
            'deduction_amount' => (float) $loan->deduction_amount, // per month

            'first_cutoff_percent' => (float) $loan->first_cutoff_percent,
            'second_cutoff_percent' => (float) $loan->second_cutoff_percent,
            'deduction_schedule' => $loan->deductionScheduleLabel(),
            'first_cutoff_amount' => round((float) $loan->deduction_amount * (float) $loan->first_cutoff_percent / 100, 2),
            'second_cutoff_amount' => round((float) $loan->deduction_amount * (float) $loan->second_cutoff_percent / 100, 2),

            'total_paid' => $paid,
            'current_balance' => $balance,
            'loan_state' => $state,

            'loan_date' => $loan->loan_date?->format('Y-m-d'),
            'deduction_start_date' =>
                $loan->deduction_start_date?->format('Y-m-d'),

            'remarks' => $loan->remarks,
            'status' => (bool) $loan->status,

            'created_by_name' => $loan->createdBy?->name,
            'created_at' => $loan->created_at?->toIso8601String(),
        ];
    }
}

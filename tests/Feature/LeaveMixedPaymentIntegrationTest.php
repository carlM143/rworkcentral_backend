<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveMixedPaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fully_paid_request_deducts_only_approved_paid_days(): void
    {
        $data = $this->setupLeaveData(5);
        Sanctum::actingAs($data['employeeUser']);

        $response = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $data['leaveType']->id,
            'requested_pay_type' => 'paid',
            'dates' => $this->dates(2, 'paid'),
        ])->assertCreated()->assertJsonPath('data.requested_pay_type', 'paid');

        Sanctum::actingAs($data['admin']);
        $this->patchJson('/api/admin/leave-approvals/'.$response->json('data.raw_id').'/approve')
            ->assertOk()
            ->assertJsonPath('data.approved_pay_type', 'paid')
            ->assertJsonPath('data.total_requested_paid_days', 2);

        $this->assertSame(3.0, $this->ledgerBalance($data['employee'], $data['leaveType']));
    }

    public function test_unpaid_request_without_policy_is_accepted_and_creates_no_credit_transactions(): void
    {
        $data = $this->setupLeaveData(null, 'Probationary');
        Sanctum::actingAs($data['employeeUser']);

        $response = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $data['leaveType']->id,
            'requested_pay_type' => 'unpaid',
            'dates' => $this->dates(3, 'unpaid'),
        ])
            ->assertCreated()
            ->assertJsonPath('data.leave_policy_id', null)
            ->assertJsonPath('data.total_unpaid_days', 3);

        Sanctum::actingAs($data['admin']);
        $this->patchJson('/api/admin/leave-approvals/'.$response->json('data.raw_id').'/approve')
            ->assertOk()
            ->assertJsonPath('data.approved_pay_type', 'unpaid');

        $this->assertDatabaseCount('leave_credit_transactions', 0);
    }

    public function test_mixed_request_deducts_only_paid_dates_and_preserves_the_split(): void
    {
        $data = $this->setupLeaveData(2);
        Sanctum::actingAs($data['employeeUser']);

        $dates = [
            ...$this->dates(2, 'paid'),
            ...$this->dates(3, 'unpaid', 3),
        ];
        $response = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $data['leaveType']->id,
            'requested_pay_type' => 'mixed',
            'reason' => 'Sick for five days',
            'dates' => $dates,
        ])
            ->assertCreated()
            ->assertJsonPath('data.requested_pay_type', 'mixed')
            ->assertJsonPath('data.total_paid_days', 2)
            ->assertJsonPath('data.total_unpaid_days', 3);

        $requestId = $response->json('data.raw_id');
        Sanctum::actingAs($data['admin']);
        $this->getJson('/api/admin/leave-approvals')
            ->assertOk()
            ->assertJsonPath('data.0.requested_pay_type', 'mixed')
            ->assertJsonPath('data.0.total_requested_days', 5)
            ->assertJsonPath('data.0.total_requested_paid_days', 2)
            ->assertJsonPath('data.0.total_requested_unpaid_days', 3)
            ->assertJsonCount(5, 'data.0.dates');
        $this->patchJson("/api/admin/leave-approvals/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('data.approved_pay_type', 'mixed')
            ->assertJsonPath('data.total_requested_paid_days', 2)
            ->assertJsonPath('data.total_requested_unpaid_days', 3);

        $this->assertSame(0.0, $this->ledgerBalance($data['employee'], $data['leaveType']));
        $this->assertSame(2, DB::table('leave_request_dates')
            ->where('leave_request_id', $requestId)
            ->where('approved_pay_type', 'paid')
            ->count());
        $this->assertSame(3, DB::table('leave_request_dates')
            ->where('leave_request_id', $requestId)
            ->where('approved_pay_type', 'unpaid')
            ->count());

        Sanctum::actingAs($data['employeeUser']);
        $credits = $this->getJson('/api/employee/leave-credits')->assertOk();
        $paidRow = collect($credits->json('data.leave_types'))
            ->firstWhere('leave_policy_id', $data['policy']->id);
        $this->assertSame(0.0, (float) $paidRow['available_credits']);
    }

    public function test_half_day_paid_request_deducts_half_a_credit(): void
    {
        $data = $this->setupLeaveData(1);
        Sanctum::actingAs($data['employeeUser']);
        $response = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $data['leaveType']->id,
            'requested_pay_type' => 'paid',
            'dates' => [[
                'leave_date' => now()->startOfYear()->addMonths(5)->toDateString(),
                'day_type' => 'half_day',
                'day_value' => 0.5,
                'requested_pay_type' => 'paid',
            ]],
        ])->assertCreated();

        Sanctum::actingAs($data['admin']);
        $this->patchJson('/api/admin/leave-approvals/'.$response->json('data.raw_id').'/approve')
            ->assertOk();

        $this->assertSame(0.5, $this->ledgerBalance($data['employee'], $data['leaveType']));
    }

    public function test_insufficient_paid_credits_are_rejected_with_available_and_requested_values(): void
    {
        $data = $this->setupLeaveData(1);
        Sanctum::actingAs($data['employeeUser']);

        $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $data['leaveType']->id,
            'requested_pay_type' => 'paid',
            'dates' => $this->dates(2, 'paid'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_credits')
            ->assertJsonFragment([
                'Insufficient paid leave credits for '.now()->year.'. Available: 1, Requested: 2.',
            ]);
    }

    public function test_highest_qualified_service_tier_is_selected_without_summing_tiers(): void
    {
        $data = $this->setupLeaveData(5, 'Regular', 0, now()->subYears(2)->toDateString());
        $higherPolicy = $this->createPolicy($data, 10, 12);
        Sanctum::actingAs($data['employeeUser']);

        $response = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $data['leaveType']->id,
            'requested_pay_type' => 'paid',
            'dates' => $this->dates(1, 'paid'),
        ])
            ->assertCreated()
            ->assertJsonPath('data.leave_policy_id', $higherPolicy->id);

        Sanctum::actingAs($data['admin']);
        $this->patchJson('/api/admin/leave-approvals/'.$response->json('data.raw_id').'/approve')
            ->assertOk();

        $this->assertSame(9.0, $this->ledgerBalance($data['employee'], $data['leaveType']));
    }

    public function test_multiple_and_repeated_approvals_cannot_overspend_or_duplicate_deductions(): void
    {
        $data = $this->setupLeaveData(5);
        Sanctum::actingAs($data['employeeUser']);
        $first = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $data['leaveType']->id,
            'requested_pay_type' => 'paid',
            'dates' => $this->dates(4, 'paid'),
        ])->assertCreated()->json('data.raw_id');
        $second = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $data['leaveType']->id,
            'requested_pay_type' => 'paid',
            'dates' => $this->dates(4, 'paid', 10),
        ])->assertCreated()->json('data.raw_id');

        Sanctum::actingAs($data['admin']);
        $this->patchJson("/api/admin/leave-approvals/{$first}/approve")->assertOk();
        $this->patchJson("/api/admin/leave-approvals/{$second}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_credits');
        $this->patchJson("/api/admin/leave-approvals/{$first}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        Sanctum::actingAs($data['employeeUser']);
        $this->patchJson("/api/employee/leave-requests/{$first}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(1, DB::table('leave_credit_transactions')
            ->where('transaction_type', 'deduction')
            ->count());
        $this->assertSame(0, DB::table('leave_credit_transactions')
            ->where('transaction_type', 'reversal')
            ->count());
        $this->assertSame(1.0, $this->ledgerBalance($data['employee'], $data['leaveType']));
    }

    public function test_pending_request_edit_updates_date_payment_classifications(): void
    {
        $data = $this->setupLeaveData(2);
        Sanctum::actingAs($data['employeeUser']);
        $requestId = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $data['leaveType']->id,
            'requested_pay_type' => 'paid',
            'dates' => $this->dates(2, 'paid'),
        ])->assertCreated()->json('data.raw_id');

        $updatedDates = $this->dates(2, 'paid');
        $updatedDates[1]['requested_pay_type'] = 'unpaid';
        $this->patchJson("/api/employee/leave-requests/{$requestId}", [
            'requested_pay_type' => 'mixed',
            'dates' => $updatedDates,
        ])
            ->assertOk()
            ->assertJsonPath('data.requested_pay_type', 'mixed')
            ->assertJsonPath('data.total_paid_days', 1)
            ->assertJsonPath('data.total_unpaid_days', 1);

        $this->assertDatabaseHas('leave_request_dates', [
            'leave_request_id' => $requestId,
            'requested_pay_type' => 'unpaid',
        ]);
    }

    public function test_historical_paid_request_without_date_classification_remains_approvable(): void
    {
        $data = $this->setupLeaveData(3);
        $leaveRequest = $data['employee']->leaveRequests()->create([
            'leave_type_id' => $data['leaveType']->id,
            'leave_policy_id' => $data['policy']->id,
            'request_no' => 'LR-HISTORICAL-001',
            'request_date' => now()->toDateString(),
            'requested_pay_type' => 'paid',
            'status' => 'pending',
        ]);
        $leaveRequest->leaveDates()->create([
            'leave_date' => now()->startOfYear()->addMonths(4)->toDateString(),
            'day_type' => 'whole_day',
            'day_value' => 1,
            'requested_pay_type' => null,
        ]);

        Sanctum::actingAs($data['admin']);
        $this->patchJson("/api/admin/leave-approvals/{$leaveRequest->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approved_pay_type', 'paid');

        $this->assertDatabaseHas('leave_request_dates', [
            'leave_request_id' => $leaveRequest->id,
            'requested_pay_type' => 'paid',
            'approved_pay_type' => 'paid',
        ]);
    }

    public function test_duplicate_dates_and_unauthorized_admin_access_are_rejected(): void
    {
        $data = $this->setupLeaveData(5);
        Sanctum::actingAs($data['employeeUser']);
        $duplicateDate = $this->dates(1, 'paid')[0];

        $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $data['leaveType']->id,
            'requested_pay_type' => 'paid',
            'dates' => [$duplicateDate, $duplicateDate],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dates.1.leave_date');

        $this->getJson('/api/admin/leave-approvals')->assertForbidden();
    }

    private function setupLeaveData(
        ?float $annualCredits,
        string $employmentTypeName = 'Regular',
        int $minimumServiceMonths = 0,
        ?string $dateHired = null
    ): array {
        $administratorRole = Role::create(['name' => 'Administrator']);
        $employeeRole = Role::create(['name' => 'Employee']);
        $admin = User::factory()->create(['role_id' => $administratorRole->id]);
        $employeeUser = User::factory()->create(['role_id' => $employeeRole->id]);
        $company = Company::create([
            'admin_id' => $admin->id,
            'company_name' => 'RWorkCentral Test Company',
            'location' => 'Manila',
            'status' => true,
        ]);
        $department = Department::create([
            'admin_id' => $admin->id,
            'company_id' => $company->id,
            'department_name' => 'Human Resources',
            'department_code' => 'HR',
            'status' => true,
        ]);
        $position = Position::create([
            'department_id' => $department->id,
            'position' => 'HR Assistant',
            'description' => 'Test position',
            'status' => true,
        ]);
        $employmentType = EmploymentType::create([
            'name' => $employmentTypeName,
            'status' => 'active',
        ]);
        $employee = Employee::create([
            'user_id' => $employeeUser->id,
            'department_id' => $department->id,
            'emp_id' => 10000001,
            'firstname' => 'Juan',
            'lastname' => 'Dela Cruz',
            'birthday' => '1990-01-01',
            'status' => true,
        ]);
        $employee->employments()->create([
            'employment_type_id' => $employmentType->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'date_hired' => $dateHired ?? now()->subYear()->toDateString(),
        ]);
        $leaveType = LeaveType::create([
            'user_id' => $admin->id,
            'leave_code' => 'SL',
            'leave_name' => 'Sick Leave',
            'status' => 'active',
        ]);

        $data = compact(
            'admin',
            'employeeUser',
            'employee',
            'employmentType',
            'leaveType'
        );
        $data['policy'] = $annualCredits === null
            ? null
            : $this->createPolicy($data, $annualCredits, $minimumServiceMonths);

        return $data;
    }

    private function createPolicy(array $data, float $annualCredits, int $minimumServiceMonths): LeavePolicy
    {
        return LeavePolicy::create([
            'leave_type_id' => $data['leaveType']->id,
            'employment_type_id' => $data['employmentType']->id,
            'is_eligible' => true,
            'is_paid' => true,
            'annual_credits' => $annualCredits,
            'min_service_months' => $minimumServiceMonths,
            'allow_carry_over' => false,
            'max_carry_over_days' => 0,
            'requires_approval' => true,
            'usage_window' => 'calendar_year',
            'validity_period' => 'annual',
            'effective_from' => now()->startOfYear()->toDateString(),
            'effective_to' => now()->endOfYear()->toDateString(),
            'status' => 'approved',
        ]);
    }

    private function dates(int $count, string $payType, int $startDay = 1): array
    {
        return collect(range($startDay, $startDay + $count - 1))
            ->map(fn (int $day): array => [
                'leave_date' => now()->startOfYear()->addMonths(5)->addDays($day)->toDateString(),
                'day_type' => 'whole_day',
                'day_value' => 1,
                'requested_pay_type' => $payType,
            ])
            ->all();
    }

    private function ledgerBalance(Employee $employee, LeaveType $leaveType): float
    {
        return round((float) DB::table('leave_credit_transactions as transactions')
            ->join('leave_policies as policies', 'policies.id', '=', 'transactions.leave_policy_id')
            ->where('transactions.employee_id', $employee->id)
            ->where('policies.leave_type_id', $leaveType->id)
            ->sum('transactions.days_delta'), 2);
    }
}

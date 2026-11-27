<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Api\Admin\SupervisorApprovalController;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MultipleDepartmentApprovalsTest extends TestCase
{
    public function test_every_approver_must_act_before_department_approval_completes(): void
    {
        // Isolated SQLite schema: no development database or seed data is touched.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('employees', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable();
            $t->string('firstname'); $t->string('lastname');
        });
        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('employee_id');
            $t->string('status'); $t->string('dept_head_status');
            $t->unsignedBigInteger('dept_head_id')->nullable();
            $t->timestamp('dept_head_acted_at')->nullable();
            $t->text('dept_head_remarks')->nullable(); $t->timestamps();
        });
        Schema::create('department_request_approvals', function (Blueprint $t) {
            $t->id(); $t->string('request_type'); $t->unsignedBigInteger('request_id');
            $t->unsignedBigInteger('user_id'); $t->string('status');
            $t->timestamp('acted_at')->nullable(); $t->text('remarks')->nullable(); $t->timestamps();
        });
        DB::table('employees')->insert(['id' => 10, 'user_id' => 10, 'firstname' => 'Requester', 'lastname' => 'Employee']);
        DB::table('employees')->insert(['id' => 20, 'user_id' => 2, 'firstname' => 'Second', 'lastname' => 'Approver']);
        DB::table('leave_requests')->insert(['id' => 1, 'employee_id' => 10, 'status' => 'pending', 'dept_head_status' => 'pending']);
        foreach ([1, 2] as $id) {
            DB::table('department_request_approvals')->insert(['request_type' => 'leave', 'request_id' => 1, 'user_id' => $id, 'status' => 'pending']);
        }
        $notifications = \Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('toAdmins')->once();
        app()->instance(NotificationService::class, $notifications);

        $controller = app(SupervisorApprovalController::class);
        $makeRequest = function (int $id) {
            $user = \Mockery::mock(User::class)->makePartial();
            $user->id = $id;
            $user->setRelation('role', null);
            $user->shouldReceive('hasPrivilege')->andReturn(true);
            $request = Request::create('/', 'PATCH');
            $request->setUserResolver(fn () => $user);
            return $request;
        };
        $controller->approve($makeRequest(1), 'leave', 1);
        $this->assertSame('pending', DB::table('leave_requests')->value('dept_head_status'));
        $this->assertSame('approved', DB::table('department_request_approvals')->where('user_id', 1)->value('status'));
        $this->assertSame('pending', DB::table('department_request_approvals')->where('user_id', 2)->value('status'));
        $pending = new \ReflectionMethod($controller, 'pendingQuery');
        $this->assertSame(0, $pending->invoke($controller, 'leave', 1)->count());
        $this->assertSame(1, $pending->invoke($controller, 'leave', 2)->count(), 'Second approver must still see the request.');
        try {
            $controller->approve($makeRequest(1), 'leave', 1);
            $this->fail('Duplicate approval must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        try {
            $controller->approve($makeRequest(3), 'leave', 1);
            $this->fail('Unassigned account must not approve.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $controller->approve($makeRequest(2), 'leave', 1);
        $this->assertSame('approved', DB::table('leave_requests')->value('dept_head_status'));
        $this->assertSame('pending', DB::table('leave_requests')->value('status'), 'HR must still approve.');
        DB::purge('sqlite');
    }
}

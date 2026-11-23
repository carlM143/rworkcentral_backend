<?php

namespace App\Observers;

use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\DepartmentHead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

class DepartmentHeadApprovalObserver
{
    private const LABELS = [
        'leave_requests' => 'Leave',
        'overtime_requests' => 'Overtime',
        'undertime_requests' => 'Undertime',
        'work_from_home' => 'Work From Home',
    ];

    public function creating(Model $request): void
    {
        $filedByEmployee = request()?->is('api/employee/*') ?? false;

        if (! $filedByEmployee || ($request->status ?? 'pending') !== 'pending') {
            $request->dept_head_status = 'skipped';

            return;
        }

        $head = DepartmentHead::approverUserIdFor((int) $request->employee_id);
        $needsSupervisor = $head || DepartmentHead::supervisorUserIds() !== [];

        $request->dept_head_id = $head;                       // null = any supervisor
        $request->dept_head_status = $needsSupervisor ? 'pending' : 'skipped';
    }

    public function created(Model $request): void
    {
        if ($request->dept_head_status !== 'pending') {
            return;
        }

        $label = self::LABELS[$request->getTable()] ?? 'Request';
        $employee = DB::table('employees')->where('id', $request->employee_id)->first(['firstname', 'lastname', 'user_id']);
        $name = $employee ? trim($employee->firstname.' '.$employee->lastname) : 'An employee';

        $recipients = $request->dept_head_id
            ? [(int) $request->dept_head_id]
            : array_diff(DepartmentHead::supervisorUserIds(), [(int) ($employee->user_id ?? 0)]);

        $this->notify($recipients, [
            'category' => 'request',
            'level' => 'info',
            'title' => "{$label} request for your approval",
            'message' => "{$name} filed {$request->request_no}. Please approve or reject it.",
            'url' => '/admin/approvals/for-my-approval',
        ]);
    }

    public function updating(Model $request): void
    {
        $approving = $request->isDirty('status') && $request->status === 'approved';

        if (! $approving || $request->getOriginal('dept_head_status') !== 'pending') {
            return;
        }

        if (request()?->boolean('override')) {
            $request->dept_head_status = 'overridden';
            $request->dept_head_acted_at = now();
            $request->dept_head_remarks = 'Approved by HR without the supervisor.';

            return;
        }

        $who = $request->dept_head_id
            ? (DepartmentHead::nameOfUser($request->dept_head_id) ?? 'the supervisor')
            : 'a supervisor';

        throw ValidationException::withMessages([
            'status' => "Waiting for {$who} to approve this request first (For My Approval). To approve it anyway, approve with override.",
        ]);
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function notify(array $userIds, array $data): void
    {
        if (! $userIds) {
            return;
        }

        try {
            $users = User::query()->whereIn('id', $userIds)->get();

            Notification::send($users, new SystemNotification($data + [
                'actor' => auth()->user()?->name,
                'subject_type' => null,
                'subject_id' => null,
            ]));
        } catch (Throwable $e) {
            Log::warning('Supervisor approval notification failed.', ['error' => $e->getMessage()]);
        }
    }
}
<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class NotificationService
{
    /** Roles that receive admin notifications (roles.name, lowercase). */
    private const ADMIN_ROLES = ['administrator', 'human resources'];

    /**
     * Administrators and HR.
     */
    public function toAdmins(
        string $category,
        string $title,
        string $message,
        ?string $url = null,
        string $level = 'info',
        ?int $exceptUserId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): void {
        $this->send(
            $this->usersWithRoles(self::ADMIN_ROLES),
            compact('category', 'title', 'message', 'url', 'level', 'subjectType', 'subjectId'),
            $exceptUserId,
        );
    }

    /**
     * The user account of an employee (Employee Portal), if it has one.
     */
    public function toEmployee(
        int $employeeId,
        string $category,
        string $title,
        string $message,
        ?string $url = null,
        string $level = 'info',
        ?int $exceptUserId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): void {
        $userId = DB::table('employees')->where('id', $employeeId)->value('user_id');

        $this->send(
            $userId ? User::query()->whereKey($userId)->get() : collect(),
            compact('category', 'title', 'message', 'url', 'level', 'subjectType', 'subjectId'),
            $exceptUserId,
        );
    }

    /**
     * @param  iterable<User>  $users
     * @param  array<string, mixed>  $data
     */
    private function send(iterable $users, array $data, ?int $exceptUserId): void
    {
        try {
            $recipients = collect($users)
                ->filter(fn (User $user) => $user->id !== $exceptUserId)
                ->unique('id')
                ->values();

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new SystemNotification([
                'category' => $data['category'],
                'level' => $data['level'],
                'title' => $data['title'],
                'message' => $data['message'],
                'url' => $data['url'],
                'actor' => auth()->user()?->name,
                'subject_type' => $data['subjectType'],
                'subject_id' => $data['subjectId'],
            ]));
        } catch (Throwable $exception) {
            Log::warning('Notification could not be sent.', [
                'title' => $data['title'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, string>  $roleNames
     * @return Collection<int, User>
     */
    private function usersWithRoles(array $roleNames): Collection
    {
        return User::query()
            ->whereHas('role', fn ($q) => $q->whereIn(DB::raw('LOWER(name)'), $roleNames))
            ->get();
    }
}
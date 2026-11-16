<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SystemNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{
     *     category: string,
     *     level: string,
     *     title: string,
     *     message: string,
     *     url: ?string,
     *     actor: ?string,
     *     subject_type: ?string,
     *     subject_id: ?int,
     * }  $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
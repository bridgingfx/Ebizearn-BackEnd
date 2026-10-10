<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app notice about a task reward: verified, released to the wallet,
 * refunded to the business, or waiting for a manual check.
 */
class TaskRewardNotice extends Notification
{
    public function __construct(
        private string $kind,
        private string $title,
        private string $body,
        private ?string $link = null,
        private array $extra = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'link' => $this->link] + $this->extra;
    }
}

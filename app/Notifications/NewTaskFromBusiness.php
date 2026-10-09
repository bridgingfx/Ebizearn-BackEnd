<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Notifications\Notification;

/** To contributors who turned on a business's bell: it published a task. */
class NewTaskFromBusiness extends Notification
{
    public function __construct(private Task $task, private string $businessName)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'new_task',
            'title' => 'New task from ' . $this->businessName,
            'body' => '"' . $this->task->title . '" — earn $' . number_format(((int) $this->task->reward_cents) / 100, 2) . '.',
            'link' => '/app/tasks/' . ($this->task->uuid ?: $this->task->id),
            'task' => ['id' => $this->task->id, 'uuid' => $this->task->uuid, 'title' => $this->task->title],
        ];
    }
}

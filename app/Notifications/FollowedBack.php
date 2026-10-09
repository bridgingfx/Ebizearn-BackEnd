<?php

namespace App\Notifications;

use App\Models\Business;
use Illuminate\Notifications\Notification;

/** To a contributor: a business you follow followed you back. */
class FollowedBack extends Notification
{
    public function __construct(private Business $business)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'followed_back',
            'title' => 'You have a new follower',
            'body' => $this->business->company_name . ' followed you back.',
            'link' => '/app/tasks',
            'business' => ['id' => $this->business->id, 'uuid' => $this->business->uuid, 'name' => $this->business->company_name],
        ];
    }
}

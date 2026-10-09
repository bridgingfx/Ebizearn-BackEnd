<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/** To a business: someone followed you. */
class NewFollower extends Notification
{
    public function __construct(private User $follower)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $country = $this->follower->profile?->country_code;

        return [
            'kind' => 'new_follower',
            'title' => 'New follower',
            'body' => $this->follower->name . ($country ? ' (' . strtoupper($country) . ')' : '') . ' started following you.',
            'link' => '/business/profile',
            'user' => [
                'id' => $this->follower->id,
                'name' => $this->follower->name,
                'country_code' => $country,
                'avatar_url' => $this->follower->profile?->avatar_url,
            ],
        ];
    }
}

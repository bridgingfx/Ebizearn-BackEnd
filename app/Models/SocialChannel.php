<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contributor's social channel, verified with a bio code by staff.
 */
class SocialChannel extends Model
{
    public const PLATFORMS = ['instagram', 'tiktok', 'youtube', 'facebook', 'x'];

    protected $fillable = [
        'user_id', 'platform', 'handle', 'profile_url', 'followers', 'verification_code', 'status',
        'rejection_reason', 'submitted_at', 'verified_at', 'reviewed_by',
        'connected_via', 'oauth_provider_user_id', 'oauth_username',
        'oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at',
        'last_robo_check_at', 'robo_check_note', 'robo_failures',
    ];

    protected $casts = [
        'followers' => 'integer',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'oauth_expires_at' => 'datetime',
        'last_robo_check_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

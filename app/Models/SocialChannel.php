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
        'last_robo_check_at', 'robo_check_note', 'robo_failures', 'oauth_scopes',
    ];

    /** OAuth tokens never leave the server (they are also encrypted at rest). */
    protected $hidden = ['oauth_access_token', 'oauth_refresh_token'];

    protected $casts = [
        'followers' => 'integer',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'oauth_expires_at' => 'datetime',
        'last_robo_check_at' => 'datetime',
    ];

    protected $appends = ['connection_health'];

    /**
     * Health of an official (OAuth) connection: connected | expiring (within
     * 7 days) | expired (token past, or the robo failed repeatedly) — or
     * "manual" for bio-code channels.
     */
    public function getConnectionHealthAttribute(): string
    {
        if ($this->connected_via !== 'oauth') {
            return 'manual';
        }
        if (empty($this->attributes['oauth_access_token'])
            || ($this->oauth_expires_at && $this->oauth_expires_at->isPast() && empty($this->attributes['oauth_refresh_token']) && $this->platform !== 'instagram')
            || ($this->oauth_expires_at && $this->oauth_expires_at->isPast() && $this->platform === 'instagram')
            || (int) ($this->attributes['robo_failures'] ?? 0) >= 3) {
            return 'expired';
        }
        if ($this->oauth_expires_at && $this->oauth_expires_at->lt(now()->addDays(7)) && empty($this->attributes['oauth_refresh_token']) && $this->platform !== 'instagram') {
            return 'expiring';
        }

        return 'connected';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

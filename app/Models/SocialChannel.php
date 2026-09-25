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
    ];

    protected $casts = [
        'followers' => 'integer',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

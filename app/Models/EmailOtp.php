<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per issued email OTP. Only the bcrypt hash of the 6-digit code
 * is persisted — the plaintext code exists solely in the email.
 */
class EmailOtp extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'code_hash',
        'attempts',
        'ip',
        'expires_at',
        'used_at',
        'invalidated_at',
    ];

    // Laravel 10.50 has no model `casts()` method form (Laravel 11+ only).
    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->used_at === null && $this->invalidated_at === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

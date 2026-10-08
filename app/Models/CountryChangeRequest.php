<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's request to change residence country, reviewed by staff.
 * Status and review fields are set in code only (not fillable).
 */
class CountryChangeRequest extends Model
{
    protected $fillable = [
        'user_id',
        'from_country',
        'to_country',
        'reason',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

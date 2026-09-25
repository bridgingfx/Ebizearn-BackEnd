<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A business's "I paid" notice. The wallet is credited only when staff approve.
 */
class DepositRequest extends Model
{
    protected $fillable = [
        'uuid', 'user_id', 'wallet_id', 'method', 'amount_cents', 'currency', 'reference', 'note', 'proof_path',
        'status', 'reviewed_by', 'reviewed_at', 'review_note', 'wallet_transaction_id',
    ];

    protected $hidden = ['proof_path'];

    protected $appends = ['has_proof'];

    protected $casts = [
        'amount_cents' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (DepositRequest $d) {
            $d->uuid ??= (string) Str::uuid();
        });
    }

    public function getHasProofAttribute(): bool
    {
        return !empty($this->attributes['proof_path']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

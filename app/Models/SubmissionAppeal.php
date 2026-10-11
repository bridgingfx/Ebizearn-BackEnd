<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SubmissionAppeal extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_UPHELD = 'upheld';
    public const STATUS_DENIED = 'denied';

    protected $fillable = [
        'uuid',
        'submission_id',
        'user_id',
        'reason',
        'status',
        'reviewer_id',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SubmissionAppeal $appeal) {
            if (empty($appeal->uuid)) {
                $appeal->uuid = (string) Str::uuid();
            }
        });
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'submission_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}

<?php

namespace App\Models;

<<<<<<< Updated upstream
use Illuminate\Database\Eloquent\Factories\HasFactory;
=======
>>>>>>> Stashed changes
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

<<<<<<< Updated upstream
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
=======
/**
 * A contributor contests a rejected proof (once per submission). Staff
 * uphold it — the proof goes back to the review queue and the normal
 * approve / reject path decides and pays — or deny it.
 */
class SubmissionAppeal extends Model
{
    protected $fillable = ['submission_id', 'user_id', 'reason'];

    protected $casts = ['decided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (SubmissionAppeal $a) {
            $a->uuid ??= (string) Str::uuid();
>>>>>>> Stashed changes
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

<<<<<<< Updated upstream
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
=======
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
>>>>>>> Stashed changes
    }
}

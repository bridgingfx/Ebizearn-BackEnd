<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class TaskSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'task_id',
        'user_id',
        'assignment_id',
        'triggered_referral_id',
        'status',
        'proof_data_json',
        'reviewer_id',
        'reviewed_at',
        'review_notes',
        // Phase 6: verification evidence
        'proof_hash',
        'device_fingerprint',
        'review_reason_code',
        'triggered_referral_reward_ids_json',
        'verification_stage',
        // Two-step review: the campaign business's recommendation.
        'business_decision',
        'business_reason',
        'business_reviewer_id',
        'business_reviewed_at',
    ];

    protected $casts = [
        'proof_data_json' => 'array',
        'reviewed_at' => 'datetime',
        'business_reviewed_at' => 'datetime',
        'triggered_referral_reward_ids_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (TaskSubmission $sub) {
            if (empty($sub->uuid)) {
                $sub->uuid = (string) Str::uuid();
            }
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TaskAssignment::class, 'assignment_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** The business user who gave the first-step decision. */
    public function businessReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'business_reviewer_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class, 'submission_id');
    }

    public function aiResult(): HasOne
    {
        return $this->hasOne(AiVerificationResult::class, 'submission_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One verification attempt of a submission's post: the initial automatic
 * check, a final check at the end of the task duration, or a manual staff
 * decision. Keeps the API checks, the AI verdict and the reason.
 */
class PostVerification extends Model
{
    protected $fillable = ['submission_id', 'stage', 'outcome', 'reason', 'api_checks_json', 'ai_json', 'api_meta_json', 'actor_id', 'checked_at'];

    protected $casts = [
        'api_checks_json' => 'array',
        'ai_json' => 'array',
        'api_meta_json' => 'array',
        'checked_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'submission_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

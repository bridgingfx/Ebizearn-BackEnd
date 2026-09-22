<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiVerificationResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'confidence_score',
        'risk_score',
        'duplicate_risk',
        'proof_quality',
        'content_match',
        'policy_match',
        'suggested_decision',
        'analysis_summary',
        'raw_payload_json',
        'ai_simulated',
        'ai_label',
    ];

    protected $casts = [
        'raw_payload_json' => 'array',
        'ai_simulated' => 'boolean',
        'confidence_score' => 'integer',
        'risk_score' => 'integer',
        'duplicate_risk' => 'integer',
        'proof_quality' => 'integer',
        'content_match' => 'integer',
        'policy_match' => 'integer',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class);
    }
}

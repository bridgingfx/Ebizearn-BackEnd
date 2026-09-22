<?php

namespace App\Services\AI;

use App\Models\TaskSubmission;

/**
 * Manual-review AI provider: performs NO automated analysis.
 *
 * Used when AI_VERIFICATION_PROVIDER=manual. The submission is flagged for
 * human review instead of receiving heuristic scores.
 */
class ManualAIProvider implements AIProviderInterface
{
    public function analyzeSubmission(TaskSubmission $submission): array
    {
        return [
            'confidence_score' => 0,
            'risk_score' => 0,
            'duplicate_risk' => 0,
            'proof_quality' => 0,
            'content_match' => 0,
            'policy_match' => 0,
            'suggested_decision' => 'flag',
            'analysis_summary' => 'AI pre-check is disabled (provider: manual). No automated analysis was performed; this submission is queued for human review.',
            'raw_payload' => [
                'provider' => 'manual',
                'simulated' => false,
                'note' => 'Manual review provider — scores intentionally zeroed, no AI ran.',
            ],
        ];
    }
}

<?php

namespace App\Services\AI;

use App\Models\TaskSubmission;

/**
 * Placeholder written at submit time when real AI review is on: the AI
 * (and, for Instagram posts, the API check) runs right after the response
 * is sent — PostVerificationService then replaces this row with the real
 * verdict. The submission sits in the manual queue meanwhile, so nothing
 * waits on the AI.
 */
class QueuedAIProvider implements AIProviderInterface
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
            'analysis_summary' => 'AI review is running — the result appears here in a moment.',
            'raw_payload' => ['provider' => 'ai', 'queued' => true, 'simulated' => false],
        ];
    }
}

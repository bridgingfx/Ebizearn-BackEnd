<?php

namespace App\Services\AI;

use App\Models\TaskSubmission;

/**
 * Pre-launch placeholder AI provider.
 *
 * IMPORTANT: every value returned here is a hard-coded heuristic, NOT real
 * AI analysis. All scores (e.g. the 93% confidence) are fabricated
 * placeholders. API payloads built from these results MUST carry
 * ai_simulated=true and the "Simulated heuristic (pre-launch)" label — see
 * VerificationService::processNewSubmission().
 */
class MockAIProvider implements AIProviderInterface
{
    public function analyzeSubmission(TaskSubmission $submission): array
    {
        // Load files
        $files = $submission->files;
        $proofData = $submission->proof_data_json ?? [];
        $hasUrl = !empty($proofData['url']);
        $hasText = !empty($proofData['text_answer']) || !empty($proofData['note']);
        $hasFile = $files->count() > 0;

        // Base confidence
        $confidenceScore = 93;
        $proofQuality = 92;
        $contentMatch = 94;
        $policyMatch = 98;
        $duplicateRisk = 4;
        $riskScore = 8;
        $suggestedDecision = 'approve';

        // Check if submission is empty
        if (!$hasUrl && !$hasText && !$hasFile) {
            $confidenceScore = 15;
            $riskScore = 85;
            $proofQuality = 20;
            $contentMatch = 10;
            $suggestedDecision = 'reject';
            $summary = 'Incomplete submission: no proof file, URL, or textual confirmation provided.';
        } elseif ($hasFile) {
            $summary = 'Proof file received: presence, basic format and size checks applied. Visual content has NOT been analyzed by real AI (pre-launch heuristic) — duplicate image hashing is not performed; URL-level duplicate checks run separately in the fraud service.';
        } elseif ($hasUrl) {
            $summary = 'URL provided: recorded for reviewer verification. Reachability and metadata checks are not automatically performed in pre-launch mode.';
        } else {
            $summary = 'Text response provided: recorded for reviewer verification. Automated semantic scoring is not active in pre-launch mode.';
        }

        return [
            'confidence_score' => $confidenceScore,
            'risk_score' => $riskScore,
            'duplicate_risk' => $duplicateRisk,
            'proof_quality' => $proofQuality,
            'content_match' => $contentMatch,
            'policy_match' => $policyMatch,
            'suggested_decision' => $suggestedDecision,
            'analysis_summary' => $summary,
            'raw_payload' => [
                'provider' => 'mock_vision_ai_v2',
                'simulated' => true,
                'latency_ms' => 342,
                'model' => 'biznetwork-vision-guard-1.0 (placeholder — no real model runs)',
                'tags_detected' => ['social_post', 'verified_engagement', 'brand_mention'],
            ],
        ];
    }
}

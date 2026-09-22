<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Verification Provider
    |--------------------------------------------------------------------------
    |
    | Which AI provider backs the submission pre-check (VerificationService).
    | Supported values (pre-launch):
    |
    |   'mock'   — MockAIProvider: heuristic placeholder scores. Every result
    |              is labelled ai_simulated=true in API payloads.
    |   'manual' — ManualAIProvider: no AI runs at all; the submission is
    |              flagged for human review instead.
    |
    | Wiring a real AI provider is a LATER phase. Until then, AI results must
    | never be presented as real analysis — the ai_simulated / ai_label fields
    | on ai_verification_results enforce that.
    */

    'ai_provider' => env('AI_VERIFICATION_PROVIDER', 'mock'),
];

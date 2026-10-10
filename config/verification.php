<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Verification Provider
    |--------------------------------------------------------------------------
    |
    | Which AI backs the submission pre-check (VerificationService):
    |
    |   'auto'   — real AI (the OpenAI / Gemini account Super Admin set in
    |              Admin → Settings → AI) reads the screenshot after the
    |              contributor submits. When no AI is configured it falls
    |              back to 'manual'.
    |   'mock'   — MockAIProvider: heuristic placeholder scores, labelled
    |              ai_simulated=true (tests / demos).
    |   'manual' — no AI runs; every submission goes to human review.
    |
    | AI only ADVISES. Nothing is approved from a screenshot alone — see the
    | Instagram rules below.
    */

    'ai_provider' => env('AI_VERIFICATION_PROVIDER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Instagram post tasks
    |--------------------------------------------------------------------------
    |
    | When the proof link is an Instagram post / reel and the contributor has
    | connected their Instagram professional account (official Meta OAuth),
    | the backend reads the post through the Instagram API and the AI
    | compares the screenshot with it. A task is approved automatically only
    | when BOTH agree; anything uncertain goes to manual review.
    |
    | At the end of the task duration the post is read again: still there →
    | the pending reward is released; deleted → the reward goes back to the
    | business that funded it; can't tell (API down, token expired) → retry,
    | then manual review.
    */

    'instagram' => [
        // Approve automatically when the API and the AI both confirm the post.
        'auto_approve' => (bool) env('IG_AUTO_APPROVE', true),
        // AI must agree (and be at least this confident) to auto-approve.
        'require_ai' => (bool) env('IG_REQUIRE_AI', true),
        'min_ai_confidence' => (int) env('IG_MIN_AI_CONFIDENCE', 80),
        // Share of the required post text / hashtags the caption must contain.
        'min_caption_match' => (int) env('IG_MIN_CAPTION_MATCH', 60),
        // Final check retry policy when the API can't confirm the post.
        'final_check_retry_hours' => (int) env('IG_FINAL_CHECK_RETRY_HOURS', 6),
        'final_check_max_attempts' => (int) env('IG_FINAL_CHECK_MAX_ATTEMPTS', 5),
        // Graph API version for graph.instagram.com.
        'graph_version' => env('IG_GRAPH_VERSION', 'v21.0'),
    ],
];

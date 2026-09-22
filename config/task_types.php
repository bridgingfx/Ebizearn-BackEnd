<?php

/*
|--------------------------------------------------------------------------
| Task types (Phase 4 / Phase 12)
|--------------------------------------------------------------------------
|
| Canonical defaults for the task_types table. The DATABASE is authoritative
| at runtime (RewardBandService reads task_types rows); this file seeds the
| table and acts as the fallback when a row is missing. Reward bands are in
| cents and come from the owner mission brief's realistic pricing:
|   follow $0.10–0.20 · like/comment $0.05–0.15 · share $0.10–0.30 ·
|   survey $0.20–2.00 · UGC higher.
|
| `is_allowed` is the per-type kill switch: like_comment ships allowed but
| flagged — incentivized engagement is only legitimate where the target
| platform's policy permits it, so Super Admin can disable the type.
*/

return [

    'defaults' => [
        [
            'key' => 'follow',
            'name' => 'Follow',
            'description' => 'Follow a brand or creator account and keep the follow for the retention period.',
            'is_allowed' => true,
            'policy_note' => null,
            'proof_required' => ['screenshot'],
            'retention_period_days' => 7,
            'fraud_rules' => ['duplicate_screenshot' => true, 'retention_check' => true],
            'reward_band_min_cents' => 10,
            'reward_band_max_cents' => 20,
            'allowed_platforms' => ['instagram', 'tiktok', 'x', 'facebook', 'youtube'],
        ],
        [
            'key' => 'like_comment',
            'name' => 'Like / Comment',
            'description' => 'Like a post and/or leave a genuine comment.',
            'is_allowed' => true,
            'policy_note' => 'Allowed only where the target platform\'s policy permits incentivized engagement. Super Admin may disable this type.',
            'proof_required' => ['screenshot', 'url'],
            'retention_period_days' => 3,
            'fraud_rules' => ['duplicate_screenshot' => true, 'comment_quality' => true],
            'reward_band_min_cents' => 5,
            'reward_band_max_cents' => 15,
            'allowed_platforms' => ['instagram', 'tiktok', 'facebook', 'youtube'],
        ],
        [
            'key' => 'share',
            'name' => 'Share',
            'description' => 'Share a post, link or campaign asset to your own audience or a community.',
            'is_allowed' => true,
            'policy_note' => null,
            'proof_required' => ['screenshot', 'url'],
            'retention_period_days' => 3,
            'fraud_rules' => ['duplicate_screenshot' => true, 'duplicate_url' => true],
            'reward_band_min_cents' => 10,
            'reward_band_max_cents' => 30,
            'allowed_platforms' => ['facebook', 'x', 'instagram', 'tiktok', 'linkedin', 'whatsapp', 'telegram'],
        ],
        [
            'key' => 'watch',
            'name' => 'Watch',
            'description' => 'Watch a video for a minimum duration and confirm completion.',
            'is_allowed' => true,
            'policy_note' => null,
            'proof_required' => ['screenshot'],
            'retention_period_days' => 0,
            'fraud_rules' => ['minimum_watch_time' => true, 'rapid_completion' => true],
            'reward_band_min_cents' => 5,
            'reward_band_max_cents' => 25,
            'allowed_platforms' => ['youtube', 'tiktok', 'instagram'],
        ],
        [
            'key' => 'app_test',
            'name' => 'Download / App Test',
            'description' => 'Install an app, complete onboarding or a defined test flow, and report.',
            'is_allowed' => true,
            'policy_note' => null,
            'proof_required' => ['screenshot', 'text'],
            'retention_period_days' => 3,
            'fraud_rules' => ['device_fingerprint' => true, 'duplicate_screenshot' => true],
            'reward_band_min_cents' => 50,
            'reward_band_max_cents' => 300,
            'allowed_platforms' => ['ios', 'android', 'web'],
        ],
        [
            'key' => 'survey',
            'name' => 'Survey',
            'description' => 'Complete a questionnaire thoughtfully and submit the confirmation code.',
            'is_allowed' => true,
            'policy_note' => null,
            'proof_required' => ['text', 'url'],
            'retention_period_days' => 0,
            'fraud_rules' => ['rapid_completion' => true, 'answer_quality' => true],
            'reward_band_min_cents' => 20,
            'reward_band_max_cents' => 200,
            'allowed_platforms' => ['web'],
        ],
        [
            'key' => 'ugc',
            'name' => 'UGC Video',
            'description' => 'Record and submit an original short video (testimonial, unboxing, review).',
            'is_allowed' => true,
            'policy_note' => null,
            'proof_required' => ['file', 'url'],
            'retention_period_days' => 30,
            'fraud_rules' => ['originality_check' => true, 'duplicate_media' => true],
            'reward_band_min_cents' => 100,
            'reward_band_max_cents' => 1000,
            'allowed_platforms' => ['tiktok', 'instagram', 'youtube'],
        ],
        [
            'key' => 'referral',
            'name' => 'Referral',
            'description' => 'Invite a new user who registers and completes a qualifying task.',
            'is_allowed' => true,
            'policy_note' => null,
            'proof_required' => ['text'],
            'retention_period_days' => 0,
            'fraud_rules' => ['multi_account' => true, 'device_fingerprint' => true],
            'reward_band_min_cents' => 25,
            'reward_band_max_cents' => 100,
            'allowed_platforms' => ['web'],
        ],
        [
            'key' => 'community',
            'name' => 'Community',
            'description' => 'Join a community (group, channel, server) and remain a member.',
            'is_allowed' => true,
            'policy_note' => null,
            'proof_required' => ['screenshot'],
            'retention_period_days' => 7,
            'fraud_rules' => ['duplicate_screenshot' => true, 'retention_check' => true],
            'reward_band_min_cents' => 10,
            'reward_band_max_cents' => 50,
            'allowed_platforms' => ['telegram', 'discord', 'facebook', 'whatsapp', 'reddit'],
        ],
    ],

];

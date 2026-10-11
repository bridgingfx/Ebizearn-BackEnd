<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\FraudEvent;
use App\Models\PostVerification;
use App\Models\SocialChannel;
use App\Models\SubmissionAppeal;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fraud\ImageFingerprint;
use App\Services\Fraud\RiskEngine;
use App\Services\Verification\PostVerificationService;
use App\Services\Verification\VerificationService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verification Agent: fraud & risk engine, YouTube subscribe verification,
 * AI evidence, appeals, and social connection health.
 */
class VerificationAgentTest extends TestCase
{
    use RefreshDatabase;

    private bool $subscribed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        app(\App\Services\Maintenance\DemoDataCleaner::class)->run();
        config(['verification.ai_provider' => 'auto', 'services.openai.key' => 'test-key']);

        Http::fake(function (HttpRequest $r) {
            $url = $r->url();
            if (str_contains($url, 'api.openai.com/v1/chat/completions')) {
                return Http::response(['choices' => [['message' => ['content' => json_encode([
                    'is_proof_of_task' => true, 'confidence' => 95, 'matches_api_post' => null, 'looks_edited_or_fake' => false,
                    'visible_action' => 'subscribed', 'cropped_or_incomplete' => false, 'manipulation_signs' => [],
                    'account_handle_seen' => 'alice', 'url_seen' => null, 'issues' => [], 'requirements_met' => ['subscribed'], 'summary' => 'Shows Subscribed.',
                ])]]]]);
            }
            if (str_contains($url, 'api.openai.com')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'yt-token-2', 'expires_in' => 3600]);
            }
            if (str_contains($url, 'googleapis.com/youtube/v3/subscriptions')) {
                return Http::response(['items' => $this->subscribed ? [['id' => 's1', 'snippet' => ['publishedAt' => now()->toIso8601String()]]] : []]);
            }

            return Http::response([], 200);
        });
    }

    // ------------------------------------------------------------------ fixtures

    private function user(string $role): User
    {
        $u = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'name' => ucfirst($role) . ' ' . Str::random(4), 'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
        Wallet::firstOrCreate(['user_id' => $u->id], ['currency' => 'USD']);

        return $u;
    }

    private function task(string $platform = 'instagram', string $title = 'Follow us', ?string $target = null): Task
    {
        $owner = $this->user('business');
        $business = Business::create(['owner_id' => $owner->id, 'company_name' => 'Bean House', 'status' => 'active']);
        $ledger = new WalletLedgerService();
        $w = Wallet::where('user_id', $owner->id)->first();
        $ledger->credit($w, 1000, 'deposit', 'Deposit');
        $ledger->hold($w, 1000, 'campaign_funding', 'Escrow');
        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'category_id' => TaskCategory::firstOrFail()->id,
            'title' => $title, 'description' => 'x', 'status' => 'active', 'platform' => $platform,
            'total_budget_cents' => 1000, 'remaining_budget_cents' => 1000, 'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 20, 'target_contributors_count' => 10, 'target_url' => $target,
        ]);

        return Task::create([
            'uuid' => (string) Str::uuid(), 'campaign_id' => $campaign->id, 'category_id' => $campaign->category_id,
            'task_type_id' => TaskType::where('key', 'follow')->firstOrFail()->id, 'title' => $title,
            'platform' => $platform, 'reward_cents' => 20, 'slots_total' => 10, 'status' => 'available', 'retention_days' => 7,
        ]);
    }

    private function png(int $seed): string
    {
        $img = imagecreatetruecolor(120, 80);
        for ($x = 0; $x < 120; $x += 10) {
            imagefilledrectangle($img, $x, 0, $x + 9, 79, imagecolorallocate($img, ($x * 2 + $seed * 37) % 256, ($x + $seed * 91) % 256, ($seed * 53) % 256));
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function jpeg(string $png): string
    {
        $img = imagecreatefromstring($png);
        ob_start();
        imagejpeg($img, null, 70);

        return (string) ob_get_clean();
    }

    private function submit(User $u, Task $task, string $imageBytes, array $proof = []): TaskSubmission
    {
        TaskAssignment::create(['uuid' => (string) Str::uuid(), 'task_id' => $task->id, 'user_id' => $u->id, 'status' => 'in_progress', 'started_at' => now()->subHour()]);
        $task->campaign->decrement('remaining_budget_cents', 20);
        $task->campaign->increment('reserved_budget_cents', 20);
        Sanctum::actingAs($u);
        $this->postJson("/api/v1/tasks/{$task->id}/submit", $proof + [
            'proof_screenshot' => 'data:image/png;base64,' . base64_encode($imageBytes),
        ])->assertStatus(201);
        $sub = TaskSubmission::where('task_id', $task->id)->where('user_id', $u->id)->firstOrFail();
        app(PostVerificationService::class)->processPending(20);

        return $sub->fresh();
    }

    private function connectYouTube(User $u): void
    {
        SocialChannel::forceCreate([
            'user_id' => $u->id, 'platform' => 'youtube', 'handle' => 'alice', 'profile_url' => 'https://www.youtube.com/channel/UCalice',
            'status' => 'verified', 'connected_via' => 'oauth', 'oauth_provider_user_id' => 'UCalice', 'oauth_username' => 'alice',
            'oauth_access_token' => Crypt::encryptString('yt-token'), 'oauth_refresh_token' => Crypt::encryptString('yt-refresh'),
            'oauth_expires_at' => now()->addHour(), 'verification_code' => 'EBZ-YT0001',
        ]);
    }

    // ------------------------------------------------------------------ tests

    public function test_image_fingerprint_survives_reencoding(): void
    {
        $png = $this->png(1);
        $a = ImageFingerprint::hash($png);
        $b = ImageFingerprint::hash($this->jpeg($png));
        $c = ImageFingerprint::hash($this->png(7));

        $this->assertLessThanOrEqual(ImageFingerprint::SAME_IMAGE_DISTANCE, ImageFingerprint::distance($a, $b));
        $this->assertGreaterThan(ImageFingerprint::SAME_IMAGE_DISTANCE, ImageFingerprint::distance($a, $c));
    }

    public function test_youtube_subscribe_is_verified_by_api_and_auto_approved_into_pending(): void
    {
        $task = $this->task('youtube', 'Subscribe to our channel', 'https://www.youtube.com/channel/UCtargetChannel0000000000');
        $alice = $this->user('contributor');
        $this->connectYouTube($alice);

        $sub = $this->submit($alice, $task, $this->png(2));

        $this->assertSame('approved', $sub->status);
        $this->assertSame('pending_duration', $sub->reward_status);
        $this->assertSame('yt-sub:UCtargetChannel0000000000', $sub->platform_media_id);
        $check = PostVerification::where('submission_id', $sub->id)->where('stage', 'initial')->firstOrFail();
        $this->assertTrue($check->api_checks_json['subscribed']);
        $this->assertSame('subscribed', $check->ai_json['evidence']['visible_action']);
        $this->assertSame('low', $check->risk_json['level']);

        // Unsubscribed before the end of the duration → refunded to the business.
        $this->subscribed = false;
        $this->travel(8)->days();
        $this->artisan('retention:release')->assertSuccessful();
        $this->assertSame('refunded', $sub->fresh()->reward_status);
        $this->assertSame(0, (int) Wallet::where('user_id', $alice->id)->value('pending_balance_cents'));
    }

    public function test_reused_image_from_another_account_blocks_auto_approval(): void
    {
        $task = $this->task('youtube', 'Subscribe to our channel', 'https://www.youtube.com/channel/UCtargetChannel0000000000');
        $first = $this->user('contributor');
        $copycat = $this->user('contributor');
        $this->connectYouTube($copycat);
        $png = $this->png(3);

        $this->submit($first, $this->task('instagram', 'Other task'), $png);
        // Same picture, re-encoded (different bytes — the per-campaign byte hash can't see it).
        $sub = $this->submit($copycat, $task, $this->jpeg($png));

        $this->assertSame('under_review', $sub->status, 'API + AI said yes, but the risk engine blocks auto-approval');
        $risk = PostVerification::where('submission_id', $sub->id)->firstOrFail()->risk_json;
        $this->assertContains('reused_image', collect($risk['flags'])->pluck('code')->all());
        $this->assertTrue($risk['blocks_auto_approval']);
        $this->assertSame(1, FraudEvent::where('submission_id', $sub->id)->where('event_type', 'reused_image')->count());
    }

    public function test_account_switching_and_handle_parsing(): void
    {
        $this->assertSame('alice', RiskEngine::handleFromUrl('https://www.instagram.com/alice/'));
        $this->assertSame('bob', RiskEngine::handleFromUrl('https://x.com/bob/status/123'));
        $this->assertSame('carol', RiskEngine::handleFromUrl('https://www.tiktok.com/@carol/video/9'));
        $this->assertNull(RiskEngine::handleFromUrl('https://www.instagram.com/p/ABC123/'));

        $task = $this->task('instagram', 'Follow us');
        $u = $this->user('contributor');
        SocialChannel::forceCreate(['user_id' => $u->id, 'platform' => 'instagram', 'handle' => 'realme', 'profile_url' => 'https://instagram.com/realme',
            'status' => 'verified', 'connected_via' => 'manual', 'verification_code' => 'EBZ-IG0001']);

        $sub = $this->submit($u, $task, $this->png(4), ['proof_url' => 'https://www.instagram.com/someoneelse/']);

        $flags = collect(PostVerification::where('submission_id', $sub->id)->firstOrFail()->risk_json['flags'])->pluck('code')->all();
        $this->assertContains('account_switching', $flags);
    }

    public function test_appeal_upheld_goes_back_to_review_and_denied_stands(): void
    {
        $task = $this->task();
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        $alice = $this->user('contributor');
        $sub = $this->submit($alice, $task, $this->png(5));
        app(VerificationService::class)->recordDecision($sub, $admin, 'rejected', 'missing_requirements', 'Screenshot unclear');
        $remainingAfterReject = (int) $task->campaign->fresh()->remaining_budget_cents;

        Sanctum::actingAs($alice);
        $detail = $this->getJson("/api/v1/contributor/task-history/{$sub->assignment_id}")->assertOk();
        $this->assertTrue($detail->json('data.can_appeal'));
        $this->postJson("/api/v1/contributor/task-history/{$sub->assignment_id}/appeal", ['reason' => 'short'])->assertStatus(422);
        $this->postJson("/api/v1/contributor/task-history/{$sub->assignment_id}/appeal", ['reason' => 'The follow is visible in the top right corner.'])->assertCreated();
<<<<<<< Updated upstream
        $this->postJson("/api/v1/contributor/task-history/{$sub->assignment_id}/appeal", ['reason' => 'Trying a second time here.'])->assertStatus(409);
=======
        $this->postJson("/api/v1/contributor/task-history/{$sub->assignment_id}/appeal", ['reason' => 'Trying a second time here.'])->assertStatus(422);
>>>>>>> Stashed changes

        Sanctum::actingAs($admin);
        $list = $this->getJson('/api/v1/admin/submission-appeals?status=open')->assertOk()->assertJsonCount(1, 'data');
        $appealId = $list->json('data.0.id');
        $this->postJson("/api/v1/admin/submission-appeals/{$appealId}/decision", ['decision' => 'uphold', 'notes' => 'Second look'])->assertOk();
<<<<<<< Updated upstream
        $this->postJson("/api/v1/admin/submission-appeals/{$appealId}/decision", ['decision' => 'deny'])->assertStatus(409);
=======
        $this->postJson("/api/v1/admin/submission-appeals/{$appealId}/decision", ['decision' => 'deny'])->assertStatus(422);
>>>>>>> Stashed changes

        $sub->refresh();
        $this->assertSame('under_review', $sub->status, 'upholding never approves by itself');
        $this->assertSame(0, (int) Wallet::where('user_id', $alice->id)->value('pending_balance_cents'));
        $this->assertSame($remainingAfterReject - 20, (int) $task->campaign->fresh()->remaining_budget_cents, 'slot reserved again');

        // The normal decision pays — reward goes to pending.
        app(VerificationService::class)->recordDecision($sub, $admin, 'approved', 'verified', 'OK on second look');
        $this->assertSame(20, (int) Wallet::where('user_id', $alice->id)->value('pending_balance_cents'));

        // Deny path on another proof.
        $bob = $this->user('contributor');
        $sub2 = $this->submit($bob, $task, $this->png(6));
        app(VerificationService::class)->recordDecision($sub2, $admin, 'rejected', 'fake_submission', 'Edited');
        Sanctum::actingAs($bob);
        $this->postJson("/api/v1/contributor/task-history/{$sub2->assignment_id}/appeal", ['reason' => 'I really did complete this task.'])->assertCreated();
        Sanctum::actingAs($admin);
        $id2 = SubmissionAppeal::where('submission_id', $sub2->id)->value('id');
        $this->postJson("/api/v1/admin/submission-appeals/{$id2}/decision", ['decision' => 'deny', 'notes' => 'Edited screenshot'])->assertOk();
        $this->assertSame('rejected', $sub2->fresh()->status);
        $this->getJson('/api/v1/admin/submission-appeals?status=denied')->assertJsonCount(1, 'data');
    }

    public function test_youtube_oauth_channel_saved_as_youtube_and_health(): void
    {
        $this->assertSame('youtube', \App\Services\Social\SocialConnectSettings::channelPlatform('google'));
        $u = $this->user('contributor');
        $this->connectYouTube($u);
        $c = SocialChannel::where('user_id', $u->id)->first();
        $this->assertSame('connected', $c->connection_health);

        $c->forceFill(['robo_failures' => 3])->save();
        $this->assertSame('expired', $c->fresh()->connection_health);

        Sanctum::actingAs($u);
        $json = json_encode($this->getJson('/api/v1/contributor/social-channels')->assertOk()->json());
        $this->assertStringNotContainsString('oauth_access_token', $json);
        $this->assertStringContainsString('connection_health', $json);
    }
}

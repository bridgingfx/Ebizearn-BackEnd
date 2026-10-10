<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\PostVerification;
use App\Models\SocialChannel;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Verification\PostVerificationService;
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
 * Instagram post tasks end to end: AI vision + official Instagram API
 * verification, reward held in PENDING for the task duration, final
 * re-check, release exactly once, refund to the funding business, retries
 * and manual review when the API can't confirm.
 */
class InstagramPostVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const POST_URL = 'https://www.instagram.com/p/ABC123xyz/';
    private const MEDIA_ID = '17900000000000001';
    private const CAPTION = 'Fresh coffee every morning at Bean House! #beanhouse #coffee';

    /** Instagram media state the fake API reports: live | deleted | down | revoked | edited */
    private string $media = 'live';
    /** AI verdict: [is_proof, confidence] */
    private array $ai = [true, 92];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config([
            'verification.ai_provider' => 'auto',
            'services.openai.key' => 'test-key',
            'verification.instagram.final_check_max_attempts' => 2,
        ]);
        $this->fakeApis();
    }

    private function fakeApis(): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            if (str_contains($url, 'api.openai.com/v1/chat/completions')) {
                [$isProof, $confidence] = $this->ai;

                return Http::response(['choices' => [['message' => ['content' => json_encode([
                    'is_proof_of_task' => $isProof, 'confidence' => $confidence, 'matches_api_post' => $isProof,
                    'looks_edited_or_fake' => false, 'issues' => $isProof ? [] : ['Screenshot shows a different post'],
                    'requirements_met' => ['post published'], 'summary' => $isProof ? 'Screenshot shows the required post.' : 'Unclear screenshot.',
                    'platform_seen' => 'instagram', 'account_handle_seen' => 'alice_ig', 'post_text_seen' => self::CAPTION,
                ])]]]]);
            }
            if (str_contains($url, 'api.openai.com')) {
                return Http::response(['results' => [['flagged' => false, 'categories' => []]]]);
            }

            if (str_contains($url, 'graph.instagram.com')) {
                $post = [
                    'id' => self::MEDIA_ID, 'permalink' => self::POST_URL, 'username' => 'alice_ig',
                    'timestamp' => now()->subMinutes(5)->toIso8601String(), 'media_type' => 'IMAGE',
                    'media_url' => 'https://cdn.test/post.jpg',
                    'caption' => $this->media === 'edited' ? 'Something else entirely' : self::CAPTION,
                ];

                return match ($this->media) {
                    'down' => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 500),
                    'revoked' => Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190]], 400),
                    'deleted' => str_contains($url, '/me/media')
                        ? Http::response(['data' => []])
                        : Http::response(['error' => ['message' => 'Object with ID does not exist', 'code' => 100, 'error_subcode' => 33]], 400),
                    default => str_contains($url, '/me/media') ? Http::response(['data' => [$post]]) : Http::response($post),
                };
            }

            return Http::response([], 200);
        });
    }

    // ------------------------------------------------------------------ fixtures

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'name' => ucfirst($role) . ' ' . Str::random(4),
            'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Wallet::firstOrCreate(['user_id' => $user->id], ['currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    /** Business with 1,000 cents escrowed for the campaign; contributor with Instagram connected. */
    private function scenario(bool $connected = true, ?string $tokenExpires = '+50 days'): array
    {
        $owner = $this->user('business');
        $business = Business::create(['owner_id' => $owner->id, 'company_name' => 'Bean House', 'status' => 'active']);
        $ledger = new WalletLedgerService();
        $bizWallet = Wallet::where('user_id', $owner->id)->first();
        $ledger->credit($bizWallet, 1000, 'deposit', 'Test deposit');
        $ledger->hold($bizWallet, 1000, 'campaign_funding', 'Escrow');

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Bean House launch', 'description' => 'Post our coffee photo with the caption.', 'status' => 'active',
            'platform' => 'instagram', 'total_budget_cents' => 1000, 'reward_per_task_cents' => 20,
            'target_contributors_count' => 10, 'remaining_budget_cents' => 1000, 'reserved_budget_cents' => 0,
        ]);
        $campaign->forceFill(['generated_content' => self::CAPTION])->save();

        $task = Task::create([
            'uuid' => (string) Str::uuid(), 'campaign_id' => $campaign->id, 'category_id' => $campaign->category_id,
            'task_type_id' => TaskType::where('key', 'follow')->firstOrFail()->id, // 7-day duration
            'title' => 'Post our coffee photo', 'platform' => 'instagram', 'reward_cents' => 20,
            'slots_total' => 10, 'status' => 'available', 'retention_days' => 7,
        ]);

        $contributor = $this->user('contributor');
        if ($connected) {
            SocialChannel::forceCreate([
                'user_id' => $contributor->id, 'platform' => 'instagram', 'handle' => 'alice_ig',
                'profile_url' => 'https://www.instagram.com/alice_ig/', 'status' => 'verified', 'connected_via' => 'oauth',
                'oauth_provider_user_id' => '1789', 'oauth_username' => 'alice_ig',
                'oauth_access_token' => Crypt::encryptString('ig-test-token'),
                'oauth_expires_at' => $tokenExpires ? now()->modify($tokenExpires) : null,
                'oauth_scopes' => 'instagram_business_basic', 'verification_code' => 'EBZ-TEST01',
            ]);
        }

        return compact('owner', 'business', 'campaign', 'task', 'contributor');
    }

    private function submit(User $contributor, Task $task): TaskSubmission
    {
        TaskAssignment::create([
            'uuid' => (string) Str::uuid(), 'task_id' => $task->id, 'user_id' => $contributor->id,
            'status' => 'in_progress', 'started_at' => now()->subHour(),
        ]);
        // What "Start task" does: the slot's reward moves from the pool to reserved.
        $task->campaign->decrement('remaining_budget_cents', $task->reward_cents);
        $task->campaign->increment('reserved_budget_cents', $task->reward_cents);
        Sanctum::actingAs($contributor);
        $this->postJson("/api/v1/tasks/{$task->id}/submit", [
            'proof_url' => self::POST_URL,
            'proof_screenshot' => 'data:image/png;base64,' . base64_encode('screenshot-' . Str::random(12)),
        ])->assertStatus(201);

        $submission = TaskSubmission::where('task_id', $task->id)->where('user_id', $contributor->id)->firstOrFail();
        // The after-response run normally does this; run it explicitly (idempotent).
        app(PostVerificationService::class)->processPending(20);

        return $submission->fresh();
    }

    private function wallet(User $user): Wallet
    {
        return Wallet::where('user_id', $user->id)->firstOrFail();
    }

    // ------------------------------------------------------------------ tests

    public function test_verified_post_is_auto_approved_and_reward_is_only_pending(): void
    {
        ['task' => $task, 'contributor' => $c, 'owner' => $owner] = $this->scenario();

        $sub = $this->submit($c, $task);

        $this->assertSame('approved', $sub->status);
        $this->assertSame('pending_duration', $sub->reward_status);
        $this->assertSame(self::MEDIA_ID, $sub->platform_media_id);
        $this->assertSame('business_wallet', $sub->funding_type);
        $this->assertSame($owner->id, (int) $sub->funding_user_id);
        $this->assertTrue($sub->final_check_due_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));

        $w = $this->wallet($c);
        $this->assertSame(0, (int) $w->available_balance_cents, 'nothing withdrawable yet');
        $this->assertSame(20, (int) $w->pending_balance_cents);

        $check = PostVerification::where('submission_id', $sub->id)->where('stage', 'initial')->firstOrFail();
        $this->assertSame('verified', $check->outcome);
        $this->assertTrue($check->api_checks_json['account_match']);
        $this->assertSame(92, $check->ai_json['confidence']);
        $this->assertStringNotContainsString('ig-test-token', json_encode($check->toArray()));

        // Nothing is released before the duration ends.
        $this->artisan('retention:release')->assertSuccessful();
        $this->assertSame(20, (int) $this->wallet($c)->pending_balance_cents);
    }

    public function test_post_still_live_releases_the_reward_exactly_once(): void
    {
        ['task' => $task, 'contributor' => $c, 'owner' => $owner] = $this->scenario();
        $sub = $this->submit($c, $task);

        $this->travel(8)->days();
        $this->artisan('retention:release')->assertSuccessful();
        $this->artisan('retention:release')->assertSuccessful();
        // A staff "release" click afterwards is a no-op too.
        app(PostVerificationService::class)->releaseReward($sub->fresh());

        $w = $this->wallet($c);
        $this->assertSame(20, (int) $w->available_balance_cents);
        $this->assertSame(0, (int) $w->pending_balance_cents);
        $this->assertSame(1, WalletTransaction::where('wallet_id', $w->id)->where('type', 'retention_release')->count());
        $this->assertSame('released', $sub->fresh()->reward_status);
        $this->assertSame(1, $c->notifications()->where('data->kind', 'reward_released')->count());
        $this->assertSame(1, $owner->notifications()->where('data->kind', 'reward_released')->count());
    }

    public function test_deleted_post_refunds_the_business_not_the_contributor(): void
    {
        ['task' => $task, 'contributor' => $c, 'owner' => $owner, 'campaign' => $campaign] = $this->scenario();
        $sub = $this->submit($c, $task);
        $this->assertSame(980, (int) $this->wallet($owner)->pending_balance_cents, 'reward left the escrow at approval');

        $this->media = 'deleted';
        $this->travel(8)->days();
        $this->artisan('retention:release')->assertSuccessful();
        $this->artisan('retention:release')->assertSuccessful();
        app(PostVerificationService::class)->refundReward($sub->fresh()); // repeated refund = no-op

        $sub->refresh();
        $this->assertSame('refunded', $sub->reward_status);
        $this->assertSame('rejected', $sub->status);
        $this->assertSame('post_removed', $sub->review_reason_code);

        $w = $this->wallet($c);
        $this->assertSame(0, (int) $w->available_balance_cents);
        $this->assertSame(0, (int) $w->pending_balance_cents);

        // Back in the business's campaign escrow, exactly once.
        $this->assertSame(1000, (int) $this->wallet($owner)->pending_balance_cents);
        $this->assertSame(1000, (int) $campaign->fresh()->remaining_budget_cents);
        $this->assertSame(1, WalletTransaction::where('reference_type', TaskSubmission::class)->where('reference_id', $sub->id)
            ->where('metadata_json->escrow_restore', true)->count());
        $this->assertNotNull($sub->refund_tx_id);
        $this->assertSame(1, $owner->notifications()->where('data->kind', 'reward_refunded')->count());
    }

    public function test_refund_after_campaign_ended_goes_to_business_available_balance(): void
    {
        ['task' => $task, 'contributor' => $c, 'owner' => $owner, 'campaign' => $campaign] = $this->scenario();
        $sub = $this->submit($c, $task);
        $campaign->update(['status' => 'completed']);

        app(PostVerificationService::class)->refundReward($sub, null, 'Post removed');
        app(PostVerificationService::class)->refundReward($sub, null, 'Post removed');

        $biz = $this->wallet($owner);
        $this->assertSame(20, (int) $biz->available_balance_cents);
        $this->assertSame(980, (int) $biz->pending_balance_cents);
        $this->assertSame(1, WalletTransaction::where('wallet_id', $biz->id)->where('type', 'campaign_refund')->count());
    }

    public function test_api_down_keeps_reward_pending_then_escalates_to_manual_review(): void
    {
        ['task' => $task, 'contributor' => $c] = $this->scenario();
        $sub = $this->submit($c, $task);

        $this->media = 'down';
        $this->travel(8)->days();
        $this->artisan('retention:release')->assertSuccessful();

        $sub->refresh();
        $this->assertSame('pending_duration', $sub->reward_status, 'never assume deleted');
        $this->assertSame(1, $sub->final_check_attempts);
        $this->assertSame(20, (int) $this->wallet($c)->pending_balance_cents);

        $this->artisan('retention:release')->assertSuccessful(); // retry not due yet
        $this->assertSame(1, $sub->fresh()->final_check_attempts);

        $this->travel(7)->hours();
        $this->artisan('retention:release')->assertSuccessful();
        $this->assertSame('reverification_required', $sub->fresh()->reward_status);
        $this->assertSame(20, (int) $this->wallet($c)->pending_balance_cents);

        // A person decides: release.
        Sanctum::actingAs(User::where('role', 'superadmin')->first() ?? $this->user('superadmin'));
        $assignmentId = $sub->assignment_id;
        $this->postJson("/api/v1/staff/task-history/{$assignmentId}/reward", ['action' => 'release', 'note' => 'Checked the post by hand'])->assertOk();
        $this->postJson("/api/v1/staff/task-history/{$assignmentId}/reward", ['action' => 'release', 'note' => 'again'])->assertStatus(422);
        $this->assertSame(20, (int) $this->wallet($c)->available_balance_cents);
    }

    public function test_revoked_instagram_token_is_inconclusive_not_a_refund(): void
    {
        ['task' => $task, 'contributor' => $c] = $this->scenario();
        $sub = $this->submit($c, $task);

        $this->media = 'revoked';
        $this->travel(8)->days();
        $this->artisan('retention:release')->assertSuccessful();

        $this->assertSame('pending_duration', $sub->fresh()->reward_status);
        $this->assertSame('inconclusive', PostVerification::where('submission_id', $sub->id)->where('stage', 'final')->latest('id')->first()->outcome);
    }

    public function test_uncertain_ai_sends_submission_to_manual_review(): void
    {
        $this->ai = [true, 55];
        ['task' => $task, 'contributor' => $c] = $this->scenario();
        $sub = $this->submit($c, $task);

        $this->assertSame('under_review', $sub->status);
        $this->assertNull($sub->reward_status);
        $this->assertSame('inconclusive', PostVerification::where('submission_id', $sub->id)->first()->outcome);
        $this->assertSame(0, (int) $this->wallet($c)->pending_balance_cents);

        // Manual approval still works and holds the reward for the duration.
        Sanctum::actingAs(User::where('email', 'admin@ebizearn.com')->firstOrFail());
        $this->postJson("/api/v1/admin/submissions/{$sub->id}/decision", [
            'decision' => 'approved', 'reason_code' => 'verified', 'notes' => 'Looked at it myself.',
        ])->assertOk();
        $this->assertSame('pending_duration', $sub->fresh()->reward_status);
        $this->assertSame(20, (int) $this->wallet($c)->pending_balance_cents);
    }

    public function test_screenshot_alone_never_approves_without_instagram_connection(): void
    {
        ['task' => $task, 'contributor' => $c] = $this->scenario(connected: false);
        $sub = $this->submit($c, $task);

        $this->assertSame('under_review', $sub->status);
        $check = PostVerification::where('submission_id', $sub->id)->firstOrFail();
        $this->assertSame('inconclusive', $check->outcome);
        $this->assertStringContainsString('not connected', $check->reason);
    }

    public function test_expired_token_and_wrong_caption_go_to_manual_review(): void
    {
        ['task' => $task, 'contributor' => $c] = $this->scenario(tokenExpires: '-1 day');
        $this->assertSame('under_review', $this->submit($c, $task)->status);

        $this->media = 'edited';
        ['task' => $task2, 'contributor' => $c2] = $this->scenario();
        $sub2 = $this->submit($c2, $task2);
        $this->assertSame('under_review', $sub2->status);
        $this->assertStringContainsString('caption', PostVerification::where('submission_id', $sub2->id)->first()->reason);
    }

    public function test_concurrent_initial_checks_only_run_once(): void
    {
        ['task' => $task, 'contributor' => $c] = $this->scenario();
        $sub = $this->submit($c, $task);

        // Already done: re-running the queue or a stale worker changes nothing.
        $this->assertNull(app(PostVerificationService::class)->verifyInitial($sub));
        app(PostVerificationService::class)->processPending(5);

        $this->assertSame(1, PostVerification::where('submission_id', $sub->id)->where('stage', 'initial')->count());
        $this->assertSame(1, WalletTransaction::where('reference_type', TaskSubmission::class)->where('reference_id', $sub->id)->where('type', 'task_reward')->count());
    }

    public function test_caption_score(): void
    {
        $svc = app(PostVerificationService::class);
        $this->assertSame(100, $svc->captionScore(self::CAPTION . ' 🔥', self::CAPTION));
        $this->assertLessThanOrEqual(50, $svc->captionScore('Fresh coffee every morning at Bean House!', self::CAPTION), 'missing hashtags');
        $this->assertLessThan(30, $svc->captionScore('Nice day', self::CAPTION));
    }
}

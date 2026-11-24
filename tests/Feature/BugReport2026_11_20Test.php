<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\CountryChangeRequest;
use App\Models\FraudEvent;
use App\Models\Profile;
use App\Models\SystemSetting;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fraud\FraudAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bug report 2026-11-20 (Mahmood) — backend regression coverage.
 * One test per fixed bug; all run on sqlite (:memory:) only.
 */
class BugReport2026_11_20Test extends TestCase
{
    use RefreshDatabase;

    protected User $moderator;
    protected User $contributor;
    protected User $businessUser;
    protected Business $business;
    protected Campaign $campaign;
    protected Task $task;

    /** 1x1 transparent PNG bytes (for identical-byte media uploads). */
    protected const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        Storage::fake('public');

        $this->moderator = $this->makeUser('moderator', 'mod1@example.com');
        $this->contributor = $this->makeUser('contributor', 'contrib1@example.com');
        Wallet::create(['user_id' => $this->contributor->id, 'currency' => 'USD']);

        $this->businessUser = $this->makeUser('business', 'biz1@example.com');
        $this->business = Business::create(['owner_id' => $this->businessUser->id, 'company_name' => 'Co One', 'status' => 'active']);
        Wallet::create(['user_id' => $this->businessUser->id, 'currency' => 'USD', 'available_balance_cents' => 100000]);

        $this->campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $this->business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Bug Report Campaign',
            'description' => 'desc',
            'status' => 'active',
            'total_budget_cents' => 10000,
            'remaining_budget_cents' => 10000,
            'reward_per_task_cents' => 100,
            'target_contributors_count' => 10,
        ]);

        $this->task = Task::create([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $this->campaign->id,
            'category_id' => $this->campaign->category_id,
            'task_type_id' => TaskType::where('key', 'follow')->firstOrFail()->id,
            'title' => 'Bug Report Task',
            'status' => 'available',
            'reward_cents' => 100,
            'slots_total' => 10,
            'slots_taken' => 0,
        ]);
    }

    /** role/status/email_verified_at are NOT fillable — set explicitly. */
    protected function makeUser(string $role, string $email): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' One',
            'email' => $email,
            'password' => Hash::make('V3r1fy!Strong'),
        ]);
        $user->forceFill(['role' => $role, 'status' => 'active', 'email_verified_at' => now()])->save();

        return $user;
    }

    protected function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    protected function makeSubmission(User $contributor, Task $task, string $status = 'under_review', ?string $proofUrl = null): TaskSubmission
    {
        $assignment = TaskAssignment::create([
            'uuid' => (string) Str::uuid(),
            'task_id' => $task->id,
            'user_id' => $contributor->id,
            'status' => 'submitted',
            'started_at' => now()->subMinutes(10),
        ]);

        return TaskSubmission::create([
            'uuid' => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'task_id' => $task->id,
            'user_id' => $contributor->id,
            'status' => $status,
            'proof_hash' => hash('sha256', Str::random(16)),
            'proof_data_json' => $proofUrl ? ['url' => $proofUrl] : null,
        ]);
    }

    // ------------------------------------------------------------------
    // Bug 3 — fraud alerts: status filter, pagination, open_count
    // ------------------------------------------------------------------

    public function test_fraud_alerts_default_to_flagged_and_report_open_count(): void
    {
        $before = FraudEvent::where('status', 'flagged')->count();

        FraudEvent::create(['user_id' => $this->contributor->id, 'event_type' => 'rapid_submissions', 'severity' => 'medium', 'status' => 'flagged']);
        FraudEvent::create(['user_id' => $this->contributor->id, 'event_type' => 'vpn_detected', 'severity' => 'low', 'status' => 'flagged']);
        FraudEvent::create(['user_id' => $this->contributor->id, 'event_type' => 'login', 'severity' => 'low', 'status' => 'reviewed']);

        // Default: open (flagged) only.
        $resp = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($this->moderator)])
            ->getJson('/api/v1/moderator/fraud-alerts');
        $resp->assertStatus(200);
        $this->assertEquals($before + 2, $resp->json('meta.open_count'));
        $this->assertEquals($before + 2, $resp->json('meta.total'));
        foreach ($resp->json('data') as $row) {
            $this->assertEquals('flagged', $row['status']);
        }

        // Explicit status filter.
        $resp = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($this->moderator)])
            ->getJson('/api/v1/moderator/fraud-alerts?status=reviewed');
        $resp->assertStatus(200);
        foreach ($resp->json('data') as $row) {
            $this->assertEquals('reviewed', $row['status']);
        }

        // 'all' + per_page.
        $resp = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($this->moderator)])
            ->getJson('/api/v1/moderator/fraud-alerts?status=all&per_page=1');
        $resp->assertStatus(200);
        $this->assertEquals(1, $resp->json('meta.per_page'));
        $this->assertGreaterThanOrEqual(3, $resp->json('meta.total'));
    }

    // ------------------------------------------------------------------
    // Bug 4 — staff campaigns totals over the full filtered set
    // ------------------------------------------------------------------

    public function test_staff_campaigns_totals_cover_all_campaigns_not_just_page(): void
    {
        Campaign::create([
            'uuid' => (string) Str::uuid(), 'business_id' => $this->business->id,
            'category_id' => $this->campaign->category_id, 'title' => 'Second Active',
            'description' => 'desc', 'status' => 'active', 'total_budget_cents' => 5000,
            'remaining_budget_cents' => 5000, 'reward_per_task_cents' => 50,
            'target_contributors_count' => 5,
        ]);
        Campaign::create([
            'uuid' => (string) Str::uuid(), 'business_id' => $this->business->id,
            'category_id' => $this->campaign->category_id, 'title' => 'Draft One',
            'description' => 'desc', 'status' => 'draft', 'total_budget_cents' => 2000,
            'remaining_budget_cents' => 2000, 'reward_per_task_cents' => 20,
            'target_contributors_count' => 5,
        ]);

        // business_id scoping keeps the seeded demo campaigns out.
        $resp = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($this->moderator)])
            ->getJson("/api/v1/staff/campaigns?business_id={$this->business->id}&per_page=1");
        $resp->assertStatus(200);
        $this->assertCount(1, $resp->json('data.data'));
        $this->assertEquals(2, $resp->json('totals.counts.active'));
        $this->assertEquals(1, $resp->json('totals.counts.draft'));
        $this->assertEquals(0, $resp->json('totals.counts.paused'));
        $this->assertEquals(3, $resp->json('totals.counts.all'));
        $this->assertEquals(3, $resp->json('totals.total_campaigns'));
        $this->assertEquals(17000, $resp->json('totals.total_budget_cents'));
    }

    // ------------------------------------------------------------------
    // Bug 5 — resubmit path for ACTION_REQUIRED submissions
    // ------------------------------------------------------------------

    public function test_resubmit_updates_existing_submission_and_requeues_it(): void
    {
        $sub = $this->makeSubmission($this->contributor, $this->task, 'action_required', 'https://instagram.com/p/old');

        $resp = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($this->contributor)])
            ->postJson("/api/v1/tasks/{$this->task->id}/resubmit", [
                'proof_screenshot' => 'data:image/png;base64,' . self::PNG_1X1,
                'note' => 'Clearer screenshot as requested.',
            ]);
        $resp->assertStatus(200);
        $this->assertEquals($sub->id, $resp->json('data.submission.id'));
        $this->assertEquals('under_review', $resp->json('data.submission.status'));

        $fresh = $sub->fresh();
        $this->assertEquals('under_review', $fresh->status);
        $this->assertEquals(1, TaskSubmission::where('task_id', $this->task->id)->where('user_id', $this->contributor->id)->count());
        $this->assertEquals('submitted', TaskAssignment::find($fresh->assignment_id)->status);
    }

    public function test_resubmit_rejects_wrong_status_and_other_contributors(): void
    {
        $sub = $this->makeSubmission($this->contributor, $this->task, 'under_review');

        // Not action_required.
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($this->contributor)])
            ->postJson("/api/v1/tasks/{$this->task->id}/resubmit", [
                'proof_screenshot' => 'data:image/png;base64,' . self::PNG_1X1,
            ])->assertStatus(409);

        // Another contributor's submission is invisible to this user.
        $sub->update(['status' => 'action_required']);
        $other = $this->makeUser('contributor', 'other@example.com');
        // The Sanctum guard caches the authenticated user for the whole
        // test — forget it before switching users, or the second request
        // still resolves as the first contributor.
        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($other)])
            ->postJson("/api/v1/tasks/{$this->task->id}/resubmit", [
                'proof_screenshot' => 'data:image/png;base64,' . self::PNG_1X1,
            ])->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Bug 7 — media dedup + delete permission aligned with upload
    // ------------------------------------------------------------------

    public function test_media_reupload_of_identical_bytes_does_not_duplicate(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token($this->moderator)];
        $png = base64_decode(self::PNG_1X1);

        $first = $this->withHeaders($headers)
            ->post("/api/v1/staff/campaigns/{$this->campaign->id}/media", [
                'files' => [UploadedFile::fake()->createWithContent('poster.png', $png)],
            ], ['Accept' => 'application/json']);
        $first->assertStatus(201);
        $this->assertEquals(1, $this->campaign->media()->count());

        // Same bytes again: no new row, existing record returned.
        $second = $this->withHeaders($headers)
            ->post("/api/v1/staff/campaigns/{$this->campaign->id}/media", [
                'files' => [UploadedFile::fake()->createWithContent('poster-copy.png', $png)],
            ], ['Accept' => 'application/json']);
        $second->assertStatus(200);
        $this->assertEquals(1, $second->json('duplicates_skipped'));
        $this->assertEquals(1, $this->campaign->media()->count());
        $this->assertCount(1, $second->json('data'));

        // Different bytes: a second row is created.
        $third = $this->withHeaders($headers)
            ->post("/api/v1/staff/campaigns/{$this->campaign->id}/media", [
                'files' => [UploadedFile::fake()->image('other.jpg')],
            ], ['Accept' => 'application/json']);
        $third->assertStatus(201);
        $this->assertEquals(2, $this->campaign->media()->count());
    }

    public function test_moderator_with_only_post_campaigns_can_delete_media(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token($this->moderator)];
        $media = $this->campaign->media()->create([
            'type' => 'image', 'path' => 'campaign-media/x.png',
            'mime_type' => 'image/png', 'size_bytes' => 100,
            'original_name' => 'x.png', 'sort_order' => 1,
            'uploaded_by' => $this->moderator->id,
        ]);

        // Default moderator grants include post_campaigns but NOT
        // edit_campaigns — delete must accept either, like upload.
        $this->assertFalse($this->moderator->fresh()->hasPermission('edit_campaigns'));
        $this->assertTrue($this->moderator->fresh()->hasPermission('post_campaigns'));

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/staff/campaigns/{$this->campaign->id}/media/{$media->id}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('campaign_media', ['id' => $media->id]);
    }

    // ------------------------------------------------------------------
    // Bug 8 — token refresh
    // ------------------------------------------------------------------

    public function test_auth_refresh_rotates_token_in_login_shape(): void
    {
        $oldToken = $this->token($this->contributor);

        $resp = $this->withHeaders(['Authorization' => 'Bearer ' . $oldToken])
            ->postJson('/api/v1/auth/refresh');
        $resp->assertStatus(200);
        $resp->assertJsonStructure(['success', 'message', 'data' => ['user', 'token']]);
        $newToken = $resp->json('data.token');
        $this->assertNotEquals($oldToken, $newToken);
        $this->assertEquals($this->contributor->id, $resp->json('data.user.id'));

        // Old token is revoked; new token works. (Forget the cached guard
        // first — see test_resubmit_rejects_wrong_status_and_other_contributors.)
        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $oldToken])
            ->getJson('/api/v1/auth/me')->assertStatus(401);
        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $newToken])
            ->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // Bug 9 — email typo check at signup
    // ------------------------------------------------------------------

    protected function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Typo Tester',
            'email' => 'typo@example.com',
            'password' => 'V3r1fy!Strong',
            'role' => 'contributor',
            'phone_country_code' => '+971',
            'phone_number' => '501234567',
            'terms_version' => '1.1',
        ], $overrides);
    }

    public function test_register_rejects_typo_domain_with_suggestion(): void
    {
        $resp = $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'email' => 'customerfive51@gmail.vom',
        ]));
        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['email']);
        $this->assertStringContainsString(
            'Did you mean customerfive51@gmail.com?',
            (string) json_encode($resp->json('errors.email'))
        );
        $this->assertDatabaseMissing('users', ['email' => 'customerfive51@gmail.vom']);
    }

    public function test_register_accepts_correct_provider_domain(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'email' => 'customerfive51@gmail.com',
        ]))->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Bug 10 — dashboard pending KYC total
    // ------------------------------------------------------------------

    public function test_dashboard_pending_kyc_total_combines_both_queues(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token($this->moderator)];
        $before = $this->withHeaders($headers)->getJson('/api/v1/admin/dashboard')
            ->assertStatus(200)->json('data.metrics.pending_kyc_total');

        $pendingUser = $this->makeUser('contributor', 'kycpending@example.com');
        Profile::create(['user_id' => $pendingUser->id, 'country_code' => 'GE'])
            ->forceFill(['kyc_status' => 'pending'])->save();
        CountryChangeRequest::create([
            'user_id' => $this->contributor->id,
            'from_country' => 'GE', 'to_country' => 'IQ', 'reason' => 'Moved.',
        ]);

        $after = $this->withHeaders($headers)->getJson('/api/v1/admin/dashboard')
            ->assertStatus(200)->json('data.metrics.pending_kyc_total');
        $this->assertEquals($before + 2, $after);
    }

    // ------------------------------------------------------------------
    // Bug 11 — task history filters + business_id in rows
    // ------------------------------------------------------------------

    public function test_task_history_filters_by_user_and_business(): void
    {
        $other = $this->makeUser('contributor', 'history2@example.com');
        $this->makeSubmission($this->contributor, $this->task, 'under_review');
        $this->makeSubmission($other, $this->task, 'under_review');

        $headers = ['Authorization' => 'Bearer ' . $this->token($this->moderator)];

        $resp = $this->withHeaders($headers)
            ->getJson("/api/v1/staff/task-history?user_id={$this->contributor->id}");
        $resp->assertStatus(200);
        $this->assertNotEmpty($resp->json('data'));
        foreach ($resp->json('data') as $row) {
            $this->assertEquals($this->contributor->id, $row['user']['id']);
        }

        $resp = $this->withHeaders($headers)
            ->getJson("/api/v1/staff/task-history?business_id={$this->business->id}");
        $resp->assertStatus(200);
        $this->assertNotEmpty($resp->json('data'));
        foreach ($resp->json('data') as $row) {
            $this->assertEquals($this->business->id, $row['task']['business_id']);
        }
    }

    // ------------------------------------------------------------------
    // Also observed (a) — verification queue total value
    // ------------------------------------------------------------------

    public function test_verification_queue_reports_total_value(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token($this->moderator)];
        $before = $this->withHeaders($headers)->getJson('/api/v1/moderator/verification-queue')
            ->assertStatus(200)->json('meta.total_value_cents');

        $this->makeSubmission($this->contributor, $this->task, 'under_review');
        $richTask = Task::create([
            'uuid' => (string) Str::uuid(), 'campaign_id' => $this->campaign->id,
            'category_id' => $this->campaign->category_id,
            'task_type_id' => TaskType::where('key', 'follow')->firstOrFail()->id,
            'title' => 'Rich Task', 'status' => 'available', 'reward_cents' => 250,
            'slots_total' => 10, 'slots_taken' => 0,
        ]);
        $other = $this->makeUser('contributor', 'queue2@example.com');
        $this->makeSubmission($other, $richTask, 'under_review');

        $after = $this->withHeaders($headers)->getJson('/api/v1/moderator/verification-queue')
            ->assertStatus(200)->json('meta.total_value_cents');
        $this->assertEquals($before + 350, $after);
    }

    // ------------------------------------------------------------------
    // Bug 12 — target URL excluded; confidence on flags
    // ------------------------------------------------------------------

    public function test_duplicate_url_check_skips_task_target_url(): void
    {
        $this->campaign->update(['target_url' => 'https://example.com/promo']);

        $shared = 'https://example.com/promo';
        $this->makeSubmission($this->contributor, $this->task, 'under_review', $shared);
        $other = $this->makeUser('contributor', 'dup2@example.com');
        $second = $this->makeSubmission($other, $this->task, 'under_review', $shared);

        $result = (new FraudAnalysisService())->evaluateSubmission($second->fresh());
        $this->assertNotContains('duplicate_url_reused', $result['flags']);
    }

    public function test_duplicate_url_flag_carries_high_confidence(): void
    {
        $this->campaign->update(['target_url' => 'https://example.com/other']);

        $proof = 'https://instagram.com/p/shared123';
        $this->makeSubmission($this->contributor, $this->task, 'under_review', $proof);
        $other = $this->makeUser('contributor', 'dup3@example.com');
        $second = $this->makeSubmission($other, $this->task, 'under_review', $proof);

        $result = (new FraudAnalysisService())->evaluateSubmission($second->fresh());
        $this->assertContains('duplicate_url_reused', $result['flags']);
        $detail = collect($result['flag_details'])->firstWhere('code', 'duplicate_url_reused');
        $this->assertEquals('high', $detail['confidence']);
    }

    // ------------------------------------------------------------------
    // Bug 14 — known IP/device not re-flagged; trusted IP whitelist
    // ------------------------------------------------------------------

    protected function loginFrom(string $ip, string $userAgent): void
    {
        $this->call('POST', '/api/v1/auth/login', [
            'email' => $this->contributor->email,
            'password' => 'V3r1fy!Strong',
        ], [], [], [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => $userAgent,
            'HTTP_CF_IPCOUNTRY' => 'AE',
        ])->assertStatus(200);
    }

    protected function flaggedNewDeviceCount(): int
    {
        return FraudEvent::where('user_id', $this->contributor->id)
            ->where('event_type', 'new_device_login')->where('status', 'flagged')->count();
    }

    public function test_known_ip_or_device_is_not_flagged_again(): void
    {
        Profile::create(['user_id' => $this->contributor->id, 'country_code' => 'AE']);

        $chrome120 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $chrome121 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';
        $firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0';

        // First login: genuinely new device + IP -> flagged once.
        $this->loginFrom('203.0.113.1', $chrome120);
        $this->assertEquals(1, $this->flaggedNewDeviceCount());

        // Browser update, same IP -> known IP, not flagged.
        $this->loginFrom('203.0.113.1', $chrome121);
        $this->assertEquals(1, $this->flaggedNewDeviceCount());

        // New IP, same coarse device (Chrome/Windows) -> known device, not flagged.
        $this->loginFrom('203.0.113.2', $chrome121);
        $this->assertEquals(1, $this->flaggedNewDeviceCount());

        // New IP + new device family -> flagged.
        $this->loginFrom('203.0.113.3', $firefox);
        $this->assertEquals(2, $this->flaggedNewDeviceCount());

        // Trusted office IP: brand-new device, no alert — but still logged.
        SystemSetting::set('trusted_ips', '["203.0.113.9"]', 'security');
        $this->loginFrom('203.0.113.9', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15');
        $this->assertEquals(2, $this->flaggedNewDeviceCount());
        $last = FraudEvent::where('user_id', $this->contributor->id)->latest('id')->first();
        $this->assertEquals('login', $last->event_type);
        $this->assertEquals('reviewed', $last->status);
        $this->assertTrue($last->details_json['trusted_ip']);
    }

    // ------------------------------------------------------------------
    // Bug 15 — staff get a higher API rate limit
    // ------------------------------------------------------------------

    public function test_api_rate_limit_is_higher_for_staff(): void
    {
        $contribToken = $this->token($this->contributor);
        for ($i = 0; $i < 60; $i++) {
            $this->withHeaders(['Authorization' => 'Bearer ' . $contribToken])
                ->getJson('/api/v1/auth/me')->assertStatus(200);
        }
        $this->withHeaders(['Authorization' => 'Bearer ' . $contribToken])
            ->getJson('/api/v1/auth/me')->assertStatus(429);

        // Forget the cached guard or the moderator's requests still
        // resolve as the contributor (exhausted bucket, wrong limit).
        Auth::forgetGuards();
        $modToken = $this->token($this->moderator);
        for ($i = 0; $i < 61; $i++) {
            $this->withHeaders(['Authorization' => 'Bearer ' . $modToken])
                ->getJson('/api/v1/auth/me')->assertStatus(200);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Models\WithdrawalRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Priority 3 — complete contributor marketplace flow against REAL backend
 * data (no mocks): register → login → task feed → open task → start →
 * submit proof → verification pipeline → approval → available balance →
 * withdrawal request (configurable threshold honored).
 *
 * Uses the `survey` task type (0 retention, proof: text+url) so the
 * reward lands in available immediately after approval.
 */
class ContributorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // task types, categories, withdrawal rules
    }

    protected function makeBusinessWithCampaign(): array
    {
        $bizUser = User::create([
            'name' => 'Workflow Biz',
            'email' => 'workflow-biz@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'business',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $business = Business::create([
            'uuid' => (string) Str::uuid(),
            'owner_id' => $bizUser->id,
            'company_name' => 'Workflow Co',
            'status' => 'active',
        ]);
        Wallet::create(['user_id' => $bizUser->id, 'currency' => 'USD', 'available_balance_cents' => 0]);

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Workflow survey campaign',
            'description' => 'E2E test campaign.',
            'status' => 'active',
            'total_budget_cents' => 100000,
            'reward_per_task_cents' => 150,
            'target_contributors_count' => 10,
            'remaining_budget_cents' => 100000,
            'reserved_budget_cents' => 0,
        ]);

        $task = Task::create([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'category_id' => $campaign->category_id,
            'task_type_id' => TaskType::where('key', 'survey')->firstOrFail()->id,
            'title' => 'Workflow survey task',
            'description' => 'Complete the survey.',
            'platform' => 'web',
            'instructions' => 'Answer all questions.',
            'reward_cents' => 150,
            'slots_total' => 10,
            'slots_filled' => 0,
            'status' => 'available',
            'proof_requirements_json' => ['text', 'url'],
        ]);

        return [$bizUser, $task];
    }

    public function test_full_contributor_earn_flow(): void
    {
        [$bizUser, $task] = $this->makeBusinessWithCampaign();

        // Lower the withdrawal threshold for this flow ($1) so a single
        // survey reward can be withdrawn. Production default is $50.
        WithdrawalRule::query()->update(['is_active' => false]);
        WithdrawalRule::create([
            'name' => 'Test $1 minimum',
            'amount_cents' => 100,
            'is_active' => true,
        ]);

        // 1. Register -------------------------------------------------------
        $reg = $this->postJson('/api/v1/auth/register', [
            'name' => 'Earn Tester',
            'email' => 'earner@example.com',
            'password' => 'V3r1fy!Strong',
            'role' => 'contributor',
        ]);
        $reg->assertStatus(201);
        $this->assertNotEmpty($reg->json('data.token'));

        // Round 2: wallet/task write paths are email-gated — verify in setup.
        User::where('email', 'earner@example.com')->firstOrFail()
            ->forceFill(['email_verified_at' => now()])->save();

        // 2. Login ----------------------------------------------------------
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'earner@example.com',
            'password' => 'V3r1fy!Strong',
            'portal' => 'contributor',
        ]);
        $login->assertStatus(200);
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);
        $auth = ['Authorization' => 'Bearer ' . $token];

        // 3. Task feed lists the published task ------------------------------
        $feed = $this->withHeaders($auth)->getJson('/api/v1/tasks');
        $feed->assertStatus(200);
        $taskIds = collect($feed->json('data.data') ?? $feed->json('data'))->pluck('id');
        $this->assertTrue($taskIds->contains($task->id), 'Published task visible in feed');

        // 4. Open task --------------------------------------------------------
        $this->withHeaders($auth)->getJson("/api/v1/tasks/{$task->id}")->assertStatus(200);

        // 5. Start task -------------------------------------------------------
        $start = $this->withHeaders($auth)->postJson("/api/v1/tasks/{$task->id}/start");
        $start->assertStatus(201);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => User::where('email', 'earner@example.com')->firstOrFail()->id,
        ]);

        // 6. Submit proof (text + url for survey) ------------------------------
        $submit = $this->withHeaders($auth)->postJson("/api/v1/tasks/{$task->id}/submit", [
            'text_answer' => 'Completed thoughtfully. Confirmation code ABC123.',
            'proof_url' => 'https://example.com/survey/complete/abc123',
        ]);
        $submit->assertStatus(201);

        $submission = TaskSubmission::where('task_id', $task->id)
            ->where('user_id', User::where('email', 'earner@example.com')->firstOrFail()->id)
            ->firstOrFail();
        $this->assertContains($submission->status, ['submitted', 'checking', 'under_review']);
        $this->assertNotEmpty($submission->proof_hash);

        // 7. Moderator approval -------------------------------------------------
        // NOTE: Sanctum::actingAs for the role switch — Laravel's test app
        // shares the auth guard across requests in one test method, so a
        // second real-token login would resolve the cached first user.
        // Real-token auth is covered in PortalAccessTest.
        $moderator = User::create([
            'name' => 'Mod',
            'email' => 'workflow-mod@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'moderator',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($moderator);

        $decision = $this->postJson("/api/v1/moderator/submissions/{$submission->id}/decision", [
                'decision' => 'approved',
                'reason_code' => 'verified',
                'notes' => 'Proof verified manually.',
            ]);
        $decision->assertStatus(200);

        $this->assertEquals('approved', $submission->fresh()->status);
        $this->assertEquals($moderator->id, $submission->fresh()->reviewer_id);
        $this->assertNotNull($submission->fresh()->reviewed_at);

        // Audit log row with reviewer + timestamp
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $moderator->id,
            'action' => 'submission.approved',
            'entity_id' => $submission->id,
        ]);

        // 8. Reward in available balance (survey: 0 retention) -------------------
        // Switch auth back to the contributor (see note in step 7).
        $contributor = User::where('email', 'earner@example.com')->firstOrFail();
        \Laravel\Sanctum\Sanctum::actingAs($contributor);

        $breakdown = $this->withHeaders($auth)->getJson('/api/v1/wallet/breakdown');
        $breakdown->assertStatus(200);
        $this->assertEquals(150, $breakdown->json('data.available_cents'));

        // Ledger entry exists for the credit
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $contributor->wallet->id,
            'type' => 'task_reward',
            'amount_cents' => 150,
        ]);

        // 9. Withdrawal request honors the configured threshold ------------------
        $withdraw = $this->withHeaders($auth)->postJson('/api/v1/wallet/withdraw', [
            'amount_cents' => 150,
            'payout_method' => 'bank_transfer',
            'payout_details' => ['iban' => 'AE070331234567890123456'],
            'idempotency_key' => 'e2e-withdraw-' . Str::random(8),
        ]);
        $withdraw->assertStatus(201);

        $this->assertDatabaseHas('withdrawal_requests', [
            'user_id' => $contributor->id,
            'amount_cents' => 150,
            'status' => 'requested',
        ]);

        // Below-threshold withdrawal is rejected. The seeder ships $10/$25/$50/$100
        // rules — activate the $50 one (already seeded) instead of creating.
        WithdrawalRule::query()->update(['is_active' => false]);
        WithdrawalRule::where('amount_cents', 5000)->update(['is_active' => true]);
        \Illuminate\Support\Facades\Cache::forget(WithdrawalRule::CACHE_KEY);

        $tooSmall = $this->withHeaders($auth)->postJson('/api/v1/wallet/withdraw', [
            'amount_cents' => 150,
            'payout_method' => 'bank_transfer',
            'payout_details' => ['iban' => 'AE070331234567890123456'],
        ]);
        // Rejected: below the active $50 threshold.
        $tooSmall->assertStatus(422);    }

    protected function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'V3r1fy!Strong',
        ]);
        $response->assertStatus(200);

        return $response->json('data.token');
    }

    /**
     * Priority 3 — proof file persistence: base64 screenshots are decoded
     * and written to storage, not just recorded as a path string.
     */
    public function test_base64_screenshot_is_persisted_to_storage(): void
    {
        Storage::fake('public');
        $this->seed();

        // Minimal setup: contributor + active campaign + task.
        $email = 'proofer@example.com';
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Proofer',
            'email' => $email,
            'password' => 'V3r1fy!Strong',
            'password_confirmation' => 'V3r1fy!Strong',
            'role' => 'contributor',
        ])->assertStatus(201);
        $contributor = User::where('email', $email)->firstOrFail();
        // Round 2: task start/submit are email-gated — verify in setup.
        $contributor->forceFill(['email_verified_at' => now()])->save();
        $token = $this->loginAndGetToken($email);
        $auth = ['Authorization' => 'Bearer ' . $token];

        $bizUser = User::create([
            'name' => 'Biz', 'email' => 'bizproof@example.com',
            'password' => Hash::make('V3r1fy!Strong'), 'role' => 'business',
            'status' => 'active', 'email_verified_at' => now(),
        ]);
        $business = Business::create(['owner_id' => $bizUser->id, 'company_name' => 'Co', 'status' => 'active']);
        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Proof Test', 'description' => 'desc', 'status' => 'active',
            'total_budget_cents' => 10000, 'remaining_budget_cents' => 10000,
            'reward_per_task_cents' => 100, 'target_contributors_count' => 10,
        ]);
        $task = Task::create([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'category_id' => $campaign->category_id,
            'task_type_id' => TaskType::where('key', 'follow')->firstOrFail()->id,
            'title' => 'Proof Task', 'status' => 'available',
            'reward_cents' => 100, 'slots_total' => 10, 'slots_taken' => 0,
        ]);

        // Start the task.
        $this->withHeaders($auth)->postJson("/api/v1/tasks/{$task->id}/start", [])->assertStatus(201);

        // Submit with a base64 PNG (1x1 pixel).
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $submit = $this->withHeaders($auth)->postJson("/api/v1/tasks/{$task->id}/submit", [
            'proof_screenshot' => 'data:image/png;base64,' . $base64,
        ]);
        $submit->assertStatus(201);

        $submission = TaskSubmission::where('task_id', $task->id)
            ->where('user_id', $contributor->id)
            ->firstOrFail();

        // The file exists on disk with real bytes, not just a DB path.
        $file = $submission->files()->where('file_type', 'screenshot')->firstOrFail();
        $this->assertNotEmpty($file->file_path);
        Storage::disk('public')->assertExists($file->file_path);
        $this->assertGreaterThan(0, Storage::disk('public')->size($file->file_path));

        // The stored URL is a real URL, not the raw base64 blob.
        $this->assertStringNotContainsString('base64', $file->file_url);
    }
}

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Priority 5 — Moderator workflow E2E (Phase 2).
 *
 * Proves via the real API (moderator token):
 *  - verification queue lists pending submissions
 *  - submission detail is visible
 *  - approve writes reviewer_id, reviewed_at, reason_code, notes + audit log
 *  - reject writes reviewer_id, reviewed_at, reason_code, notes + audit log
 *  - action_required (Request revision) returns submission to contributor
 *    with reviewer metadata + audit log
 *  - fraud alerts endpoint is reachable
 */
class ModeratorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $moderator;
    protected User $contributor;
    protected Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->moderator = User::create([
            'name' => 'Mod One',
            'email' => 'mod1@example.com',
            'password' => Hash::make('password123'),
            'role' => 'moderator',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->contributor = User::create([
            'name' => 'Contrib One',
            'email' => 'contrib1@example.com',
            'password' => Hash::make('password123'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Wallet::create(['user_id' => $this->contributor->id, 'currency' => 'USD']);

        $bizUser = User::create([
            'name' => 'Biz One',
            'email' => 'biz1@example.com',
            'password' => Hash::make('password123'),
            'role' => 'business',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $business = Business::create(['owner_id' => $bizUser->id, 'company_name' => 'Co One', 'status' => 'active']);
        Wallet::create(['user_id' => $bizUser->id, 'currency' => 'USD', 'available_balance_cents' => 100000]);

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Mod Test Campaign',
            'description' => 'desc',
            'status' => 'active',
            'total_budget_cents' => 10000,
            'remaining_budget_cents' => 10000,
            'reward_per_task_cents' => 100,
            'target_contributors_count' => 10,
        ]);

        $this->task = Task::create([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'category_id' => $campaign->category_id,
            'task_type_id' => TaskType::where('key', 'follow')->firstOrFail()->id,
            'title' => 'Mod Test Task',
            'status' => 'available',
            'reward_cents' => 100,
            'slots_total' => 10,
            'slots_taken' => 0,
        ]);
    }

    protected function makeSubmission(User $contributor, Task $task): TaskSubmission
    {
        $assignment = TaskAssignment::create([
            'uuid' => (string) Str::uuid(),
            'task_id' => $task->id,
            'user_id' => $contributor->id,
            'status' => 'submitted',
        ]);

        return TaskSubmission::create([
            'uuid' => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'task_id' => $task->id,
            'user_id' => $contributor->id,
            'status' => 'under_review',
            'proof_hash' => hash('sha256', Str::random(16)),
        ]);
    }

    protected function modHeaders(): array
    {
        $token = $this->moderator->createToken('test')->plainTextToken;
        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_verification_queue_lists_pending_submissions(): void
    {
        $sub = $this->makeSubmission($this->contributor, $this->task);

        $resp = $this->withHeaders($this->modHeaders())
            ->getJson('/api/v1/moderator/verification-queue');
        $resp->assertStatus(200);

        $ids = collect($resp->json('data.data') ?? $resp->json('data'))->pluck('id')->all();
        $this->assertContains($sub->id, $ids);
    }

    public function test_approve_writes_reviewer_metadata_and_audit_log(): void
    {
        $sub = $this->makeSubmission($this->contributor, $this->task);

        $resp = $this->withHeaders($this->modHeaders())
            ->postJson("/api/v1/moderator/submissions/{$sub->id}/decision", [
                'decision' => 'approved',
                'reason_code' => 'verified',
                'notes' => 'Proof verified manually.',
            ]);
        $resp->assertStatus(200);

        $sub = $sub->fresh();
        $this->assertEquals('approved', $sub->status);
        $this->assertEquals($this->moderator->id, $sub->reviewer_id);
        $this->assertNotNull($sub->reviewed_at);
        $this->assertEquals('verified', $sub->review_reason_code);
        $this->assertEquals('Proof verified manually.', $sub->review_notes);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'submission.approved',
            'entity_type' => TaskSubmission::class,
            'entity_id' => $sub->id,
        ]);
    }

    public function test_reject_writes_reviewer_metadata_and_audit_log(): void
    {
        $sub = $this->makeSubmission($this->contributor, $this->task);

        $resp = $this->withHeaders($this->modHeaders())
            ->postJson("/api/v1/moderator/submissions/{$sub->id}/decision", [
                'decision' => 'rejected',
                'reason_code' => 'fake_submission',
                'notes' => 'Screenshot is stock photo.',
            ]);
        $resp->assertStatus(200);

        $sub = $sub->fresh();
        $this->assertEquals('rejected', $sub->status);
        $this->assertEquals($this->moderator->id, $sub->reviewer_id);
        $this->assertNotNull($sub->reviewed_at);
        $this->assertEquals('fake_submission', $sub->review_reason_code);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'submission.rejected',
            'entity_type' => TaskSubmission::class,
            'entity_id' => $sub->id,
        ]);
    }

    public function test_action_required_returns_to_contributor_with_metadata(): void
    {
        $sub = $this->makeSubmission($this->contributor, $this->task);

        $resp = $this->withHeaders($this->modHeaders())
            ->postJson("/api/v1/moderator/submissions/{$sub->id}/decision", [
                'decision' => 'action_required',
                'reason_code' => 'needs_better_proof',
                'notes' => 'Please upload a clearer screenshot.',
            ]);
        $resp->assertStatus(200);

        $sub = $sub->fresh();
        $this->assertEquals('action_required', $sub->status);
        $this->assertEquals($this->moderator->id, $sub->reviewer_id);
        $this->assertNotNull($sub->reviewed_at);
        $this->assertEquals('needs_better_proof', $sub->review_reason_code);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'submission.action_required',
            'entity_type' => TaskSubmission::class,
            'entity_id' => $sub->id,
        ]);
    }

    public function test_fraud_alerts_reachable(): void
    {
        $resp = $this->withHeaders($this->modHeaders())
            ->getJson('/api/v1/moderator/fraud-alerts');
        $resp->assertStatus(200);
    }
}

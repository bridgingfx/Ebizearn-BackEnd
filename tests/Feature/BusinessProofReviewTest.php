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
 * Two-step proof review: the campaign's business approves / rejects first
 * (no money moves), staff with review_submissions confirm, and only the
 * final approval credits the contributor. Every step is audit-logged.
 */
class BusinessProofReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $contributor;
    protected User $bizUser;
    protected Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->contributor = $this->user('contributor', 'contrib@example.com');
        Wallet::create(['user_id' => $this->contributor->id, 'currency' => 'USD']);

        $this->bizUser = $this->user('business', 'biz@example.com');
        $business = Business::create(['owner_id' => $this->bizUser->id, 'company_name' => 'Acme', 'status' => 'active']);
        Wallet::create(['user_id' => $this->bizUser->id, 'currency' => 'USD', 'available_balance_cents' => 100000]);

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Acme Launch',
            'description' => 'desc',
            'status' => 'active',
            'total_budget_cents' => 10000,
            'remaining_budget_cents' => 10000,
            'reward_per_task_cents' => 150,
            'target_contributors_count' => 10,
        ]);

        $this->task = Task::create([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'category_id' => $campaign->category_id,
            'task_type_id' => TaskType::where('key', 'follow')->firstOrFail()->id,
            'title' => 'Follow Acme',
            'status' => 'available',
            'reward_cents' => 150,
            'slots_total' => 10,
            'slots_taken' => 0,
        ]);
    }

    protected function user(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role) . ' User',
            'email' => $email,
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function submission(): TaskSubmission
    {
        $assignment = TaskAssignment::create([
            'uuid' => (string) Str::uuid(),
            'task_id' => $this->task->id,
            'user_id' => $this->contributor->id,
            'status' => 'submitted',
        ]);

        return TaskSubmission::create([
            'uuid' => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'task_id' => $this->task->id,
            'user_id' => $this->contributor->id,
            'status' => 'under_review',
            'proof_hash' => hash('sha256', Str::random(16)),
        ]);
    }

    protected function balance(): int
    {
        $w = Wallet::where('user_id', $this->contributor->id)->first();
        return $w->available_balance_cents + $w->pending_balance_cents;
    }

    public function test_business_approval_is_recorded_but_only_staff_confirmation_pays(): void
    {
        $sub = $this->submission();

        Sanctum::actingAs($this->bizUser);
        $this->postJson("/api/v1/business/submissions/{$sub->uuid}/decision", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.business_decision', 'approved')
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.business_reviewer.name', 'Business User');

        $this->assertSame(0, $this->balance(), 'A business approval alone must not move money.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'submission.business_approved', 'entity_id' => $sub->id, 'actor_id' => $this->bizUser->id]);

        // Staff see the business decision and can filter on it.
        $admin = $this->user('superadmin', 'super@example.com');
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/verification-queue?business_decision=approved')
            ->assertOk()
            ->assertJsonPath('data.0.id', $sub->id)
            ->assertJsonPath('data.0.business_decision', 'approved');
        $this->getJson('/api/v1/admin/verification-queue?business_decision=rejected')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/admin/submissions/{$sub->id}/decision", [
            'decision' => 'approved', 'reason_code' => 'verified', 'notes' => 'Confirmed business approval.',
        ])->assertOk()->assertJsonFragment(['message' => "Proof approved. The reward has been released to the contributor's wallet."]);

        $this->assertSame(150, $this->balance());
        $this->assertSame($admin->id, $sub->fresh()->reviewer_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'submission.approved', 'entity_id' => $sub->id, 'actor_id' => $admin->id]);

        // Once final, the business can no longer change it.
        Sanctum::actingAs($this->bizUser);
        $this->postJson("/api/v1/business/submissions/{$sub->uuid}/decision", ['decision' => 'reject', 'reason' => 'Changed my mind'])
            ->assertStatus(422);
    }

    public function test_business_rejection_needs_a_reason_and_can_be_changed_before_final(): void
    {
        $sub = $this->submission();
        Sanctum::actingAs($this->bizUser);

        $this->postJson("/api/v1/business/submissions/{$sub->id}/decision", ['decision' => 'reject'])->assertStatus(422);
        $this->postJson("/api/v1/business/submissions/{$sub->id}/decision", ['decision' => 'reject', 'reason' => 'Screenshot is cropped'])
            ->assertOk()->assertJsonPath('data.business_decision', 'rejected')->assertJsonPath('data.business_reason', 'Screenshot is cropped');
        $this->postJson("/api/v1/business/submissions/{$sub->id}/decision", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.business_decision', 'approved');
    }

    public function test_business_cannot_review_other_businesses_proofs_or_without_permission(): void
    {
        $sub = $this->submission();

        $other = $this->user('business', 'other@example.com');
        Business::create(['owner_id' => $other->id, 'company_name' => 'Other', 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/business/submissions/{$sub->id}/decision", ['decision' => 'approve'])->assertNotFound();

        // Super Admin can switch the capability off for a business account.
        $this->bizUser->syncPermissionOverrides([], ['review_campaign_proofs']);
        Sanctum::actingAs($this->bizUser->fresh());
        $this->postJson("/api/v1/business/submissions/{$sub->id}/decision", ['decision' => 'approve'])->assertForbidden();

        // Contributors cannot use it at all.
        Sanctum::actingAs($this->contributor);
        $this->postJson("/api/v1/business/submissions/{$sub->id}/decision", ['decision' => 'approve'])->assertForbidden();
    }
}

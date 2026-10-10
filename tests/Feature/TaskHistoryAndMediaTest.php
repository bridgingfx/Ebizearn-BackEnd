<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Profile;
use App\Models\SubmissionFile;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin → Task History (who took which task, proof, status) and campaign
 * photos / videos uploaded by staff and shown on the task page.
 */
class TaskHistoryAndMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    protected function makeUser(string $role): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' ' . Str::random(4),
            'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Profile::forceCreate(['user_id' => $user->id, 'country_code' => 'AE']);

        return $user;
    }

    protected function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    protected function makeTask(): Task
    {
        $owner = $this->makeUser('business');
        $business = Business::forceCreate(['owner_id' => $owner->id, 'company_name' => 'Bean House', 'status' => 'active']);
        $category = TaskCategory::forceCreate(['slug' => 'c-' . Str::random(6), 'name' => 'Social', 'is_active' => true]);
        $campaign = Campaign::forceCreate([
            'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'category_id' => $category->id,
            'title' => 'Coffee launch', 'description' => 'Post about our coffee', 'status' => 'active',
            'platform' => 'instagram', 'target_countries_json' => ['ALL'],
            'total_budget_cents' => 10000, 'remaining_budget_cents' => 10000, 'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 100, 'target_contributors_count' => 50,
        ]);

        return Task::forceCreate([
            'uuid' => (string) Str::uuid(), 'campaign_id' => $campaign->id, 'category_id' => $category->id,
            'title' => 'Follow Our Page', 'status' => 'available', 'reward_cents' => 100, 'slots_total' => 50,
        ]);
    }

    public function test_task_history_lists_who_took_what_with_proof_and_status(): void
    {
        $task = $this->makeTask();
        $alice = $this->makeUser('contributor');
        $bob = $this->makeUser('contributor');

        // Seeded demo data may already hold assignments — count relative to it.
        $this->actAs($this->makeUser('superadmin'));
        $before = $this->getJson('/api/v1/staff/task-history')->json('meta.counts');

        $a1 = TaskAssignment::forceCreate(['task_id' => $task->id, 'user_id' => $alice->id, 'status' => 'submitted', 'started_at' => now()]);
        $sub = TaskSubmission::forceCreate([
            'uuid' => (string) Str::uuid(), 'task_id' => $task->id, 'user_id' => $alice->id, 'assignment_id' => $a1->id,
            'status' => 'under_review', 'proof_data_json' => ['url' => 'https://www.instagram.com/alice'],
        ]);
        SubmissionFile::forceCreate([
            'submission_id' => $sub->id, 'file_type' => 'screenshot', 'file_path' => 'proofs/x.png',
            'file_url' => 'https://cdn.test/proofs/x.png', 'file_size_bytes' => 10, 'mime_type' => 'image/png',
        ]);
        TaskAssignment::forceCreate(['task_id' => $task->id, 'user_id' => $bob->id, 'status' => 'in_progress', 'started_at' => now()]);

        $list = $this->getJson('/api/v1/staff/task-history')->assertOk();
        $list->assertJsonPath('meta.counts.all', $before['all'] + 2)
            ->assertJsonPath('meta.counts.in_review', $before['in_review'] + 1)
            ->assertJsonPath('meta.counts.in_progress', $before['in_progress'] + 1);

        $this->getJson('/api/v1/staff/task-history?status=in_review&search=' . urlencode($alice->name))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.name', $alice->name)
            ->assertJsonPath('data.0.task.title', 'Follow Our Page')
            ->assertJsonPath('data.0.proof.images', 1)
            ->assertJsonPath('data.0.proof.has_link', true);

        $this->getJson('/api/v1/staff/task-history?search=' . urlencode($bob->name))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'in_progress');

        $this->getJson("/api/v1/staff/task-history/{$a1->id}")->assertOk()
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.submission.files.0.file_url', 'https://cdn.test/proofs/x.png')
            ->assertJsonPath('data.next_decisions', ['approved', 'rejected', 'action_required']);
    }

    public function test_task_history_needs_the_permission(): void
    {
        $this->actAs($this->makeUser('contributor'));
        $this->getJson('/api/v1/staff/task-history')->assertForbidden();

        $this->actAs($this->makeUser('admin'));
        $this->getJson('/api/v1/staff/task-history')->assertOk();
    }

    public function test_staff_upload_photos_and_videos_and_contributors_see_them(): void
    {
        $task = $this->makeTask();
        $this->actAs($this->makeUser('superadmin'));

        $res = $this->post("/api/v1/staff/campaigns/{$task->campaign_id}/media", [
            'files' => [
                UploadedFile::fake()->image('flyer.jpg', 800, 800),
                UploadedFile::fake()->create('promo.mp4', 2048, 'video/mp4'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $res->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'image')
            ->assertJsonPath('data.1.type', 'video');
        $mediaId = $res->json('data.1.id');

        $this->post("/api/v1/staff/campaigns/{$task->campaign_id}/media", [
            'files' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->actAs($this->makeUser('contributor'));
        $this->getJson("/api/v1/tasks/{$task->uuid}")->assertOk()->assertJsonCount(2, 'data.campaign.media');

        $this->actAs($this->makeUser('superadmin'));
        $this->deleteJson("/api/v1/staff/campaigns/{$task->campaign_id}/media/{$mediaId}")->assertOk()->assertJsonCount(1, 'data');
    }
}

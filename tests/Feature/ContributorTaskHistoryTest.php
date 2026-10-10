<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\SubmissionFile;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Verification\VerificationService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contributor CRM → Task History: only the signed-in contributor's own
 * tasks, and a chronological timeline from real records.
 */
class ContributorTaskHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        app(\App\Services\Maintenance\DemoDataCleaner::class)->run(); // start without demo rows
    }

    private function user(string $role): User
    {
        $u = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'name' => ucfirst($role) . ' ' . Str::random(4), 'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
        Wallet::firstOrCreate(['user_id' => $u->id], ['currency' => 'USD']);

        return $u;
    }

    private function task(): Task
    {
        $owner = $this->user('business');
        $business = Business::create(['owner_id' => $owner->id, 'company_name' => 'Bean House', 'status' => 'active']);
        $ledger = new WalletLedgerService();
        $w = Wallet::where('user_id', $owner->id)->first();
        $ledger->credit($w, 1000, 'deposit', 'Deposit');
        $ledger->hold($w, 1000, 'campaign_funding', 'Escrow');
        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Launch', 'description' => 'Post about us', 'status' => 'active', 'platform' => 'instagram',
            'total_budget_cents' => 1000, 'remaining_budget_cents' => 980, 'reserved_budget_cents' => 20,
            'reward_per_task_cents' => 20, 'target_contributors_count' => 10,
        ]);

        return Task::create([
            'uuid' => (string) Str::uuid(), 'campaign_id' => $campaign->id, 'category_id' => $campaign->category_id,
            'task_type_id' => TaskType::where('key', 'follow')->firstOrFail()->id, 'title' => 'Follow Bean House',
            'platform' => 'instagram', 'reward_cents' => 20, 'slots_total' => 10, 'status' => 'available', 'retention_days' => 7,
        ]);
    }

    private function submitted(User $u, Task $task): TaskAssignment
    {
        $a = TaskAssignment::create(['uuid' => (string) Str::uuid(), 'task_id' => $task->id, 'user_id' => $u->id, 'status' => 'submitted', 'started_at' => now()->subHour()]);
        $s = TaskSubmission::create(['task_id' => $task->id, 'user_id' => $u->id, 'assignment_id' => $a->id, 'status' => 'under_review',
            'proof_data_json' => ['url' => 'https://instagram.com/beanhouse']]);
        SubmissionFile::create(['submission_id' => $s->id, 'file_type' => 'screenshot', 'file_path' => 'proofs/x.png',
            'file_url' => 'https://cdn.test/x.png', 'file_size_bytes' => 10, 'mime_type' => 'image/png']);

        return $a;
    }

    public function test_history_lists_only_my_tasks_and_timeline_is_real(): void
    {
        $task = $this->task();
        $me = $this->user('contributor');
        $other = $this->user('contributor');
        $mine = $this->submitted($me, $task);
        $theirs = $this->submitted($other, $task);
        $started = TaskAssignment::create(['uuid' => (string) Str::uuid(), 'task_id' => $this->task()->id, 'user_id' => $me->id, 'status' => 'in_progress', 'started_at' => now()]);

        $this->travel(5)->minutes();
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        app(VerificationService::class)->recordDecision(TaskSubmission::where('assignment_id', $mine->id)->first(), $admin, 'approved', 'verified', 'Looks good');

        Sanctum::actingAs($me);
        $list = $this->getJson('/api/v1/contributor/task-history')->assertOk();
        $list->assertJsonPath('meta.counts.all', 2)->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$mine->id, $started->id], collect($list->json('data'))->pluck('id')->all());
        $this->getJson('/api/v1/contributor/task-history?status=pending_reward')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);

        // Someone else's task: 404, nothing leaks.
        $this->getJson("/api/v1/contributor/task-history/{$theirs->id}")->assertNotFound();

        $detail = $this->getJson("/api/v1/contributor/task-history/{$mine->id}")->assertOk();
        $detail->assertJsonPath('data.task.title', 'Follow Bean House')
            ->assertJsonPath('data.task.duration_days', 7)
            ->assertJsonPath('data.reward_status', 'pending_duration');
        $kinds = collect($detail->json('data.timeline'))->pluck('kind')->all();
        foreach (['started', 'submitted', 'evidence', 'decision', 'payment', 'scheduled'] as $k) {
            $this->assertContains($k, $kinds);
        }
        $json = json_encode($detail->json());
        $this->assertStringNotContainsString($admin->name, $json, 'reviewer identity is not exposed');
        $this->assertStringContainsString('eBizEarn review team', $json);

        // A started task with no proof has a real (short) timeline, not dummy steps.
        $this->getJson("/api/v1/contributor/task-history/{$started->id}")->assertOk()
            ->assertJsonCount(1, 'data.timeline')->assertJsonPath('data.submission', null);
    }

    public function test_staff_and_businesses_cannot_use_contributor_history(): void
    {
        Sanctum::actingAs($this->user('business'));
        $this->getJson('/api/v1/contributor/task-history')->assertForbidden();
    }
}

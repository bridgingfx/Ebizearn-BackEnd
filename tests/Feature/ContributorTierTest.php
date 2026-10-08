<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\ContributorRankTier;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\Contributors\ContributorTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contributor levels go up with completed (approved) tasks, using the
 * thresholds admin sets on the Contributor Ranks page. Staff can set a
 * level by hand and lock it.
 */
class ContributorTierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
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
        Profile::forceCreate(['user_id' => $user->id, 'country_code' => 'AE', 'contributor_level' => 'starter']);

        return $user;
    }

    protected function addSubmissions(User $user, int $approved, int $rejected = 0): void
    {
        $owner = $this->makeUser('business');
        $business = Business::forceCreate(['owner_id' => $owner->id, 'company_name' => 'Tier Probe Co', 'status' => 'active']);
        $category = TaskCategory::forceCreate(['slug' => 'tier-' . Str::random(6), 'name' => 'Tier Probe', 'is_active' => true]);
        $campaign = Campaign::forceCreate([
            'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'category_id' => $category->id,
            'title' => 'Tier probe', 'description' => 'tier test', 'status' => 'active',
            'total_budget_cents' => 100000, 'remaining_budget_cents' => 100000, 'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 50, 'target_contributors_count' => 1000,
        ]);

        foreach (array_merge(array_fill(0, $approved, 'approved'), array_fill(0, $rejected, 'rejected')) as $status) {
            $task = Task::forceCreate([
                'uuid' => (string) Str::uuid(), 'campaign_id' => $campaign->id, 'category_id' => $category->id,
                'title' => 'Tier task', 'status' => 'available', 'reward_cents' => 50, 'slots_total' => 1000,
            ]);
            TaskSubmission::forceCreate(['uuid' => (string) Str::uuid(), 'task_id' => $task->id, 'user_id' => $user->id, 'status' => $status]);
        }
    }

    public function test_levels_follow_admin_set_task_thresholds(): void
    {
        $svc = new ContributorTierService();
        // Defaults: explorer 10, trusted 50, pro 200, elite 500 approved tasks.
        $this->assertSame('starter', $svc->evaluate(9));
        $this->assertSame('explorer', $svc->evaluate(10));
        $this->assertSame('trusted', $svc->evaluate(50));

        // Admin lowers the Explorer requirement to 3 tasks.
        ContributorRankTier::where('level', 'explorer')->update(['required_tasks' => 3]);
        $this->assertSame('explorer', $svc->evaluate(3));
    }

    public function test_completed_tasks_move_a_contributor_up_and_reversals_back_down(): void
    {
        ContributorRankTier::where('level', 'explorer')->update(['required_tasks' => 3]);
        ContributorRankTier::where('level', 'trusted')->update(['required_tasks' => 6]);
        $user = $this->makeUser('contributor');

        // Rejections don't hold anyone back — only completed tasks count.
        $this->addSubmissions($user, 4, 10);
        $this->assertSame('explorer', (new ContributorTierService())->recalculateFor($user->fresh()));
        $profile = $user->profile()->first();
        $this->assertSame('explorer', $profile->contributor_level);
        $this->assertSame(4, (int) $profile->completed_tasks_count);

        $this->addSubmissions($user, 2);
        $this->assertSame('trusted', (new ContributorTierService())->recalculateFor($user->fresh()));

        // An approval is reversed: back below the Trusted requirement.
        TaskSubmission::where('user_id', $user->id)->where('status', 'approved')->limit(1)->update(['status' => 'rejected']);
        $this->assertSame('explorer', (new ContributorTierService())->recalculateFor($user->fresh()));
    }

    public function test_staff_can_set_and_lock_a_level(): void
    {
        $admin = $this->makeUser('admin');
        $contributor = $this->makeUser('contributor');
        $this->addSubmissions($contributor, 12); // explorer by tasks

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/users/{$contributor->id}/level", ['level' => 'pro', 'locked' => true])
            ->assertOk()->assertJsonPath('data.level', 'pro');

        // Locked: completing tasks does not change it.
        $this->assertSame('pro', (new ContributorTierService())->recalculateFor($contributor->fresh()));

        // Unlocked: back to what the completed tasks earn.
        $this->patchJson("/api/v1/admin/users/{$contributor->id}/level", ['level' => 'pro', 'locked' => false])
            ->assertOk()->assertJsonPath('data.level', 'explorer');

        // Levels are for contributors only.
        $business = $this->makeUser('business');
        $this->patchJson("/api/v1/admin/users/{$business->id}/level", ['level' => 'pro', 'locked' => true])->assertStatus(422);
    }

    public function test_no_profile_does_not_crash(): void
    {
        $user = User::forceCreate([
            'name' => 'No profile', 'email' => 'noprofile@example.com', 'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'contributor', 'status' => 'active',
        ]);

        $this->assertSame('starter', (new ContributorTierService())->recalculateFor($user));
    }
}

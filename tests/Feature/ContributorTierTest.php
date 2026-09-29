<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\TaskCategory;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\Contributors\ContributorTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Earned contributor tiers: tiers are computed from real submission history
 * (approved count + approval rate), never purchased or hand-assigned.
 */
class ContributorTierTest extends TestCase
{
    use RefreshDatabase;

    protected function makeContributor(string $email): User
    {
        $user = User::create([
            'name' => 'Contributor ' . $email,
            'email' => $email,
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Profile::create(['user_id' => $user->id, 'contributor_level' => 'starter']);

        return $user;
    }

    protected function addSubmissions(User $user, int $approved, int $rejected): void
    {
        $bizUser = User::create([
            'name' => 'Biz tier probe',
            'email' => 'biz-tier-' . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'business',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $business = Business::create([
            'owner_id' => $bizUser->id,
            'company_name' => 'Tier Probe Co',
            'status' => 'active',
        ]);
        $category = TaskCategory::create([
            'slug' => 'tier-probe-' . Str::random(6),
            'name' => 'Tier Probe',
            'is_active' => true,
        ]);
        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => $category->id,
            'title' => 'Tier probe campaign',
            'description' => 'tier test',
            'status' => 'active',
            'total_budget_cents' => 100000,
            'remaining_budget_cents' => 100000,
            'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 50,
            'target_contributors_count' => 1000,
        ]);

        $makeTask = function () use ($campaign, $category) {
            return Task::create([
                'uuid' => (string) Str::uuid(),
                'campaign_id' => $campaign->id,
                'category_id' => $category->id,
                'title' => 'Tier probe task',
                'status' => 'available',
                'reward_cents' => 50,
                'slots_total' => 1000,
            ]);
        };

        for ($i = 0; $i < $approved; $i++) {
            TaskSubmission::create([
                'uuid' => (string) Str::uuid(),
                'task_id' => $makeTask()->id,
                'user_id' => $user->id,
                'status' => 'approved',
            ]);
        }
        for ($i = 0; $i < $rejected; $i++) {
            TaskSubmission::create([
                'uuid' => (string) Str::uuid(),
                'task_id' => $makeTask()->id,
                'user_id' => $user->id,
                'status' => 'rejected',
            ]);
        }
    }

    public function test_evaluate_thresholds(): void
    {
        $svc = new ContributorTierService();

        $this->assertSame('starter', $svc->evaluate(0, 100.0));
        $this->assertSame('explorer', $svc->evaluate(5, 60.0));
        // Rate below threshold blocks promotion even with enough tasks.
        $this->assertSame('starter', $svc->evaluate(30, 50.0));
        $this->assertSame('trusted', $svc->evaluate(20, 75.0));
        $this->assertSame('pro', $svc->evaluate(50, 85.0));
        $this->assertSame('elite', $svc->evaluate(150, 90.0));
    }

    public function test_recalculate_promotes_from_real_history(): void
    {
        $user = $this->makeContributor('tier1@example.com');
        $this->addSubmissions($user, 25, 5); // 83.3% approval

        $tier = (new ContributorTierService())->recalculateFor($user);

        $this->assertSame('trusted', $tier);
        $profile = $user->profile->fresh();
        $this->assertSame('trusted', $profile->contributor_level);
        $this->assertSame(25, (int) $profile->completed_tasks_count);
        $this->assertEqualsWithDelta(83.33, (float) $profile->approval_rate, 0.01);
    }

    public function test_recalculate_demotes_when_rate_drops(): void
    {
        $user = $this->makeContributor('tier2@example.com');
        $this->addSubmissions($user, 60, 0);

        $this->assertSame('pro', (new ContributorTierService())->recalculateFor($user));

        // A wave of rejections drops the rate below the pro bar.
        $this->addSubmissions($user, 0, 30); // 66.7% approval

        $this->assertSame('explorer', (new ContributorTierService())->recalculateFor($user));
        $this->assertSame('explorer', $user->profile->fresh()->contributor_level);
    }

    public function test_no_profile_does_not_crash(): void
    {
        $user = User::create([
            'name' => 'No profile',
            'email' => 'noprofile@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'contributor',
            'status' => 'active',
        ]);

        $this->assertSame('starter', (new ContributorTierService())->recalculateFor($user));
    }
}

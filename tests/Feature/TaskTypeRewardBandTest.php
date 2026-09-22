<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\TaskCategory;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4 (task types) + Phase 12 (reward bands).
 * - public catalog exposes bands and policy flags
 * - task creation (staff + business) enforces bands -> 422 outside
 * - disabled task types are rejected
 * - Super-Admin ops can update bands (audited); others get 403
 */
class TaskTypeRewardBandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeModerator(): User
    {
        return User::create([
            'name' => 'Mod',
            'email' => 'mod-' . Str::random(6) . '@example.com',
            'password' => Hash::make('password123'),
            'role' => 'moderator',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function makeSuperAdmin(): User
    {
        // Superadmins are no longer seeded (created only via
        // `php artisan superadmin:create`), so tests create their own.
        return User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin-' . Str::random(6) . '@example.com',
            'password' => Hash::make('password123'),
            'role' => 'superadmin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function makeBusiness(string $email): User
    {
        $user = User::create([
            'name' => 'Biz ' . $email,
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => 'business',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Business::create(['owner_id' => $user->id, 'company_name' => 'Co ' . $email, 'status' => 'active']);
        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    protected function makeCampaign(Business $business): Campaign
    {
        return Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Band test campaign ' . Str::random(6),
            'description' => 'band test',
            'status' => 'active',
            'total_budget_cents' => 100000,
            'remaining_budget_cents' => 100000,
            'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 15,
            'target_contributors_count' => 100,
        ]);
    }

    protected function taskPayload(Campaign $campaign, array $overrides = []): array
    {
        return array_merge([
            'campaign_id' => $campaign->id,
            'task_type_key' => 'follow',
            'title' => 'Follow our brand page',
            'platform' => 'instagram',
            'country_code' => 'AE',
            'instructions' => 'Follow and keep the follow for 7 days.',
            'reward_cents' => 15,
            'slots_total' => 10,
            'estimated_minutes' => 2,
            'difficulty' => 'easy',
        ], $overrides);
    }

    public function test_task_types_list_is_public_and_shows_bands(): void
    {
        $response = $this->getJson('/api/v1/task-types');

        $response->assertStatus(200)->assertJson(['success' => true]);

        $types = collect($response->json('data'))->keyBy('key');

        $this->assertTrue($types->has('follow'));
        $this->assertEquals(10, $types['follow']['reward_band_min_cents']);
        $this->assertEquals(20, $types['follow']['reward_band_max_cents']);
        $this->assertEquals(['screenshot'], $types['follow']['proof_required']);

        // like_comment ships allowed but flagged: incentivized engagement is
        // only legitimate where platform policy permits it.
        $this->assertTrue($types['like_comment']['is_allowed']);
        $this->assertNotEmpty($types['like_comment']['policy_note']);

        $this->assertEquals(20, $types['survey']['reward_band_min_cents']);
        $this->assertEquals(200, $types['survey']['reward_band_max_cents']);
    }

    public function test_staff_creates_task_within_band(): void
    {
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        $campaign = $this->makeCampaign($this->makeBusiness('band-biz@example.com')->business);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/staff/tasks', $this->taskPayload($campaign));

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertEquals(15, $response->json('data.reward_cents'));
        $this->assertEquals('follow', $response->json('data.task_type.key'));
        $this->assertEquals('instagram', $response->json('data.platform'));
    }

    public function test_staff_task_reward_outside_band_is_422(): void
    {
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        $campaign = $this->makeCampaign($this->makeBusiness('band-biz2@example.com')->business);

        Sanctum::actingAs($admin);

        // follow band is $0.10–$0.20; $0.50 is outside it.
        $response = $this->postJson('/api/v1/staff/tasks', $this->taskPayload($campaign, ['reward_cents' => 50]));

        $response->assertStatus(422);
        $this->assertStringContainsString('0.10', $response->json('message'));
        $this->assertStringContainsString('0.20', $response->json('message'));
    }

    public function test_disabled_task_type_is_rejected(): void
    {
        TaskType::where('key', 'like_comment')->update(['is_allowed' => false]);

        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        $campaign = $this->makeCampaign($this->makeBusiness('band-biz3@example.com')->business);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/staff/tasks', $this->taskPayload($campaign, [
            'task_type_key' => 'like_comment',
            'reward_cents' => 10,
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('disabled', strtolower($response->json('message')));
    }

    public function test_moderator_can_create_tasks_via_permission_gate(): void
    {
        $moderator = $this->makeModerator();
        $campaign = $this->makeCampaign($this->makeBusiness('band-biz4@example.com')->business);

        Sanctum::actingAs($moderator);

        // Moderators reach the staff API through EnsurePermission
        // (manage_task_templates), not through the admin role.
        $this->postJson('/api/v1/staff/tasks', $this->taskPayload($campaign))
            ->assertStatus(201);
    }

    public function test_contributor_cannot_create_staff_tasks(): void
    {
        $contributor = User::where('email', 'sarah@ebizearn.com')->firstOrFail();
        $campaign = $this->makeCampaign($this->makeBusiness('band-biz5@example.com')->business);

        Sanctum::actingAs($contributor);

        $this->postJson('/api/v1/staff/tasks', $this->taskPayload($campaign))
            ->assertStatus(403);
    }

    public function test_business_task_creation_enforces_bands(): void
    {
        $biz = $this->makeBusiness('band-biz6@example.com');
        $campaign = $this->makeCampaign($biz->business);

        Sanctum::actingAs($biz);

        // survey band is $0.20–$2.00; $5.00 is outside it.
        $this->postJson('/api/v1/business/tasks', $this->taskPayload($campaign, [
            'task_type_key' => 'survey',
            'reward_cents' => 500,
        ]))->assertStatus(422);

        $this->postJson('/api/v1/business/tasks', $this->taskPayload($campaign, [
            'task_type_key' => 'survey',
            'reward_cents' => 150,
        ]))->assertStatus(201);
    }

    public function test_ops_superadmin_updates_band_and_it_takes_effect(): void
    {
        $superadmin = $this->makeSuperAdmin();
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();
        $campaign = $this->makeCampaign($this->makeBusiness('band-biz7@example.com')->business);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/staff/tasks', $this->taskPayload($campaign, ['reward_cents' => 25]))
            ->assertStatus(422);

        Sanctum::actingAs($superadmin);

        $this->patchJson('/api/v1/ops/task-types/follow', [
            'reward_band_min_cents' => 10,
            'reward_band_max_cents' => 25,
        ])->assertStatus(200)
            ->assertJsonPath('data.reward_band_max_cents', 25);

        // Pricing change leaves an audit trail.
        $this->assertTrue(
            AuditLog::where('action', 'task_type.band_updated')
                ->where('entity_type', TaskType::class)
                ->exists()
        );

        // The new band is enforced immediately.
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/staff/tasks', $this->taskPayload($campaign, ['reward_cents' => 25]))
            ->assertStatus(201);
    }

    public function test_ops_band_update_rejects_min_above_max(): void
    {
        $superadmin = $this->makeSuperAdmin();

        Sanctum::actingAs($superadmin);

        $this->patchJson('/api/v1/ops/task-types/follow', [
            'reward_band_min_cents' => 50,
            'reward_band_max_cents' => 25,
        ])->assertStatus(422);
    }

    public function test_non_superadmin_cannot_update_bands(): void
    {
        $admin = User::where('email', 'admin@ebizearn.com')->firstOrFail();

        Sanctum::actingAs($admin);

        // The ops group is role:superadmin — admins get 403.
        $this->patchJson('/api/v1/ops/task-types/follow', [
            'reward_band_max_cents' => 99,
        ])->assertStatus(403);

        $this->assertEquals(20, TaskType::where('key', 'follow')->firstOrFail()->reward_band_max_cents);
    }

    public function test_legacy_campaign_store_requires_task_type_and_band(): void
    {
        $biz = $this->makeBusiness('band-biz8@example.com');
        $wallet = Wallet::where('user_id', $biz->id)->firstOrFail();
        app(\App\Services\Wallet\WalletLedgerService::class)
            ->credit($wallet, 100000, 'campaign_funding', 'Top-up');

        Sanctum::actingAs($biz);

        $payload = fn (array $overrides) => array_merge([
            'title' => 'Legacy campaign',
            'description' => 'Legacy flow campaign.',
            'category_id' => TaskCategory::firstOrFail()->id,
            'reward_per_task_cents' => 20,
            'target_contributors_count' => 10,
            'instructions_markdown' => 'Do the thing.',
        ], $overrides);

        // No task type: rejected — there is no untyped bypass.
        $this->postJson('/api/v1/business/campaigns', $payload([]))->assertStatus(422);

        // Out-of-band reward: rejected with the band named.
        $response = $this->postJson('/api/v1/business/campaigns', $payload([
            'task_type_key' => 'follow',
            'reward_per_task_cents' => 500,
        ]));
        $response->assertStatus(422);
        $this->assertStringContainsString('0.10', $response->json('message'));

        // Valid type + in-band reward: created, and the task carries the
        // type contract (proof requirements + retention).
        $campaignId = $this->postJson('/api/v1/business/campaigns', $payload([
            'task_type_key' => 'follow',
        ]))->assertStatus(201)->json('data.id');

        $task = \App\Models\Task::where('campaign_id', $campaignId)->firstOrFail();
        $this->assertEquals('follow', $task->taskType->key);
        $this->assertSame(20, (int) $task->reward_cents);
        $this->assertNotEmpty($task->proof_required_json);
        $this->assertSame(7, (int) $task->retention_days);
    }
}

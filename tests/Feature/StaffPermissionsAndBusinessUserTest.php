<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\FraudEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Granular staff permissions (tasks: access / create / edit / delete;
 * campaigns: access / post / edit / delete), admin-created business user
 * accounts, and the one-time old task data cleanup.
 *
 * Every action is checked twice: the default grant works, and revoking it
 * (role matrix or per-user deny) makes the backend refuse with 403.
 */
class StaffPermissionsAndBusinessUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function staff(string $role): User
    {
        return User::create([
            'name' => ucfirst($role) . ' ' . Str::random(4),
            'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function revokeFromRole(string $role, string ...$permissions): void
    {
        Role::where('name', $role)->firstOrFail()->permissions()
            ->detach(Permission::whereIn('name', $permissions)->pluck('id'));
    }

    private function fundedCampaign(): Campaign
    {
        $campaign = Campaign::where('status', 'active')->firstOrFail();
        $campaign->update(['remaining_budget_cents' => 100000]);

        return $campaign;
    }

    private function taskPayload(Campaign $campaign): array
    {
        return [
            'campaign_id' => $campaign->id,
            'task_type_key' => 'follow',
            'title' => 'New task ' . Str::random(4),
            'reward_cents' => 20,
            'slots_total' => 5,
        ];
    }

    private function freshTask(): Task
    {
        return Task::create([
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $this->fundedCampaign()->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'task_type_id' => TaskType::where('key', 'follow')->value('id'),
            'title' => 'Untouched task',
            'reward_cents' => 20,
            'status' => 'available',
            'slots_total' => 5,
            'slots_taken' => 0,
        ]);
    }

    // ------------------------------------------------------------------
    // Tasks
    // ------------------------------------------------------------------

    public function test_admin_and_moderator_can_create_edit_and_delete_tasks_by_default(): void
    {
        foreach (['admin', 'moderator'] as $role) {
            Sanctum::actingAs($this->staff($role));
            $campaign = $this->fundedCampaign();

            $this->getJson('/api/v1/staff/tasks')->assertOk();
            $this->getJson('/api/v1/staff/tasks/campaign-options')->assertOk();

            $id = $this->postJson('/api/v1/staff/tasks', $this->taskPayload($campaign))->assertStatus(201)->json('data.id');
            $this->patchJson("/api/v1/staff/tasks/{$id}", ['title' => "Edited by {$role}", 'status' => 'paused'])
                ->assertOk()
                ->assertJsonPath('data.title', "Edited by {$role}")
                ->assertJsonPath('data.status', 'paused');
            $this->deleteJson("/api/v1/staff/tasks/{$id}")->assertOk();
            $this->assertSoftDeleted('tasks', ['id' => $id]);
        }
    }

    public function test_revoking_task_permissions_blocks_each_action(): void
    {
        $task = $this->freshTask();
        $this->revokeFromRole('moderator', 'create_tasks', 'edit_tasks', 'delete_tasks');

        Sanctum::actingAs($this->staff('moderator'));
        $this->getJson('/api/v1/staff/tasks')->assertOk(); // access stays
        $this->getJson('/api/v1/staff/tasks/campaign-options')->assertStatus(403);
        $this->postJson('/api/v1/staff/tasks', $this->taskPayload($this->fundedCampaign()))->assertStatus(403);
        $this->patchJson("/api/v1/staff/tasks/{$task->id}", ['title' => 'x'])->assertStatus(403);
        $this->deleteJson("/api/v1/staff/tasks/{$task->id}")->assertStatus(403);
        $this->assertNotNull(Task::find($task->id));

        // Without task access the list itself is closed.
        $this->revokeFromRole('moderator', 'manage_task_templates');
        $this->getJson('/api/v1/staff/tasks')->assertStatus(403);
    }

    public function test_per_user_deny_overrides_role_grant_for_task_delete(): void
    {
        $task = $this->freshTask();
        $admin = $this->staff('admin');
        $admin->directPermissions()->attach(Permission::where('name', 'delete_tasks')->value('id'), ['is_denied' => true]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/staff/tasks/{$task->id}")->assertStatus(403);
        $this->patchJson("/api/v1/staff/tasks/{$task->id}", ['title' => 'Still editable'])->assertOk();
    }

    public function test_superadmin_keeps_every_task_action_even_with_roles_revoked(): void
    {
        $this->revokeFromRole('admin', 'create_tasks', 'edit_tasks', 'delete_tasks');
        Sanctum::actingAs($this->staff('superadmin'));

        $id = $this->postJson('/api/v1/staff/tasks', $this->taskPayload($this->fundedCampaign()))->assertStatus(201)->json('data.id');
        $this->patchJson("/api/v1/staff/tasks/{$id}", ['title' => 'SA edit'])->assertOk();
        $this->deleteJson("/api/v1/staff/tasks/{$id}")->assertOk();
    }

    public function test_task_with_contributor_activity_cannot_be_deleted(): void
    {
        $task = $this->freshTask();
        $task->update(['slots_taken' => 1]);

        Sanctum::actingAs($this->staff('admin'));
        $this->deleteJson("/api/v1/staff/tasks/{$task->id}")->assertStatus(422);
    }

    public function test_non_staff_cannot_reach_task_or_campaign_management(): void
    {
        foreach (['brand@ebizearn.com', 'sarah@ebizearn.com'] as $email) {
            Sanctum::actingAs(User::where('email', $email)->firstOrFail());
            $this->getJson('/api/v1/staff/tasks')->assertStatus(403);
            $this->postJson('/api/v1/staff/tasks', $this->taskPayload($this->fundedCampaign()))->assertStatus(403);
            $this->getJson('/api/v1/staff/campaigns')->assertStatus(403);
        }
    }

    // ------------------------------------------------------------------
    // Campaigns
    // ------------------------------------------------------------------

    public function test_campaign_permissions_per_role(): void
    {
        $campaign = $this->fundedCampaign();

        // Moderator defaults: access + post, but no edit / delete.
        Sanctum::actingAs($this->staff('moderator'));
        $this->getJson('/api/v1/staff/campaigns')->assertOk();
        $this->getJson('/api/v1/staff/campaigns/business-options')->assertOk();
        $this->patchJson("/api/v1/staff/campaigns/{$campaign->id}", ['title' => 'x'])->assertStatus(403);
        $this->deleteJson("/api/v1/staff/campaigns/{$campaign->id}")->assertStatus(403);

        // Admin defaults: everything.
        Sanctum::actingAs($this->staff('admin'));
        $this->patchJson("/api/v1/staff/campaigns/{$campaign->id}", ['title' => 'Admin edit'])->assertOk();

        // Super Admin grants moderators edit.
        Role::where('name', 'moderator')->firstOrFail()->permissions()
            ->attach(Permission::where('name', 'edit_campaigns')->value('id'));
        Sanctum::actingAs($this->staff('moderator'));
        $this->patchJson("/api/v1/staff/campaigns/{$campaign->id}", ['title' => 'Moderator edit'])->assertOk();
    }

    public function test_revoking_campaign_access_and_post_permission(): void
    {
        $this->revokeFromRole('admin', 'post_campaigns');
        Sanctum::actingAs($this->staff('admin'));
        $this->getJson('/api/v1/staff/campaigns')->assertOk();
        $this->getJson('/api/v1/staff/campaigns/business-options')->assertStatus(403);
        $this->postJson('/api/v1/staff/campaigns', ['business_id' => 1])->assertStatus(403);

        $this->revokeFromRole('admin', 'manage_campaigns');
        $this->getJson('/api/v1/staff/campaigns')->assertStatus(403);
    }

    public function test_business_options_list_active_businesses_with_balance(): void
    {
        Sanctum::actingAs($this->staff('moderator'));
        $res = $this->getJson('/api/v1/staff/campaigns/business-options')->assertOk();
        $this->assertContains('eBizEarn', collect($res->json('data'))->pluck('company_name')->all());
        $this->assertArrayHasKey('available_balance_cents', $res->json('data.0'));
    }

    // ------------------------------------------------------------------
    // Business user accounts
    // ------------------------------------------------------------------

    private function businessPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nino Owner',
            'email' => 'Owner.New@Example.com',
            'password' => 'Busin3ss!Pass',
            'company_name' => 'Tbilisi Coffee Co',
            'website' => 'https://coffee.example.com',
            'industry' => 'Food & Beverage',
        ], $overrides);
    }

    public function test_admin_creates_business_user_who_can_sign_in(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        $res = $this->postJson('/api/v1/admin/businesses', $this->businessPayload(['role' => 'admin']))
            ->assertStatus(201)
            ->assertJsonPath('data.role', 'business')
            ->assertJsonPath('data.business.company_name', 'Tbilisi Coffee Co');

        $user = User::findOrFail($res->json('data.id'));
        $this->assertSame('owner.new@example.com', $user->email);
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull(Wallet::where('user_id', $user->id)->first());
        $this->assertDatabaseHas('audit_logs', ['action' => 'business_user.created', 'entity_id' => $user->id]);

        // Real password login through the business portal.
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner.new@example.com',
            'password' => 'Busin3ss!Pass',
            'portal' => 'business',
        ])->assertOk();
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        // Business permissions only — never staff ones.
        $permissions = $login->json('data.user.permissions');
        $this->assertContains('create_campaigns', $permissions);
        $this->assertNotContains('manage_users', $permissions);
        $this->assertNotContains('create_tasks', $permissions);

        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => "Bearer {$token}"];
        $this->getJson('/api/v1/business/dashboard', $headers)->assertOk();
        $this->getJson('/api/v1/admin/dashboard', $headers)->assertStatus(403);
        $this->getJson('/api/v1/staff/tasks', $headers)->assertStatus(403);

        // Staff portals refuse the account.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner.new@example.com',
            'password' => 'Busin3ss!Pass',
            'portal' => 'moderator',
        ])->assertStatus(403);
    }

    public function test_business_user_creation_is_validated_and_permission_gated(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        $this->postJson('/api/v1/admin/businesses', $this->businessPayload(['password' => 'weak']))->assertStatus(422);
        $this->postJson('/api/v1/admin/businesses', $this->businessPayload(['email' => 'brand@ebizearn.com']))->assertStatus(422);
        $this->postJson('/api/v1/admin/businesses', $this->businessPayload(['company_name' => '']))->assertStatus(422);

        // Moderators are not admins.
        Sanctum::actingAs($this->staff('moderator'));
        $this->postJson('/api/v1/admin/businesses', $this->businessPayload())->assertStatus(403);

        // Super Admin can take the permission away from admins.
        $this->revokeFromRole('admin', 'create_business_users');
        Sanctum::actingAs($this->staff('admin'));
        $this->postJson('/api/v1/admin/businesses', $this->businessPayload())->assertStatus(403);

        Sanctum::actingAs($this->staff('superadmin'));
        $this->postJson('/api/v1/admin/businesses', $this->businessPayload())->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Migrations
    // ------------------------------------------------------------------

    public function test_permission_split_migration_preserves_existing_access(): void
    {
        // A custom state from before the split: a role holding only the old
        // combined permission, and a user denied it.
        $mod = Role::where('name', 'moderator')->firstOrFail();
        $mod->permissions()->detach(Permission::whereIn('name', ['manage_campaigns', 'post_campaigns', 'create_tasks', 'edit_tasks', 'delete_tasks'])->pluck('id'));
        $denied = $this->staff('admin');
        $denied->directPermissions()->attach(Permission::where('name', 'manage_task_templates')->value('id'), ['is_denied' => true]);

        (require database_path('migrations/2026_10_07_000001_split_task_and_campaign_permissions.php'))->up();

        $names = $mod->permissions()->pluck('permissions.name')->all();
        foreach (['manage_campaigns', 'post_campaigns', 'create_tasks', 'edit_tasks', 'delete_tasks'] as $p) {
            $this->assertContains($p, $names);
        }
        $this->assertFalse($denied->fresh()->hasPermission('create_tasks'));
        $this->assertFalse($denied->fresh()->hasPermission('manage_campaigns'));
        $this->assertTrue($this->staff('admin')->hasPermission('create_business_users'));
    }

    public function test_reset_migration_keeps_only_test_logins_and_configuration(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('proofs/old.png', 'png');
        Storage::disk('local')->put('support/1/a.txt', 'x');

        $super = $this->staff('superadmin');
        $extraAdmin = $this->staff('admin');
        $contributor = $this->staff('contributor');
        $task = Task::firstOrFail();

        $assignmentId = DB::table('task_assignments')->insertGetId([
            'task_id' => $task->id, 'user_id' => $contributor->id, 'status' => 'submitted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $submissionId = DB::table('task_submissions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'task_id' => $task->id, 'user_id' => $contributor->id, 'assignment_id' => $assignmentId,
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('submission_files')->insert([
            'submission_id' => $submissionId, 'file_path' => 'proofs/old.png', 'file_url' => '/storage/proofs/old.png',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ai_verification_results')->insert([
            'submission_id' => $submissionId, 'analysis_summary' => 'ok', 'created_at' => now(), 'updated_at' => now(),
        ]);
        FraudEvent::create(['user_id' => $contributor->id, 'submission_id' => $submissionId, 'event_type' => 'duplicate_proof']);
        $brand = User::where('email', 'brand@ebizearn.com')->firstOrFail();
        Wallet::updateOrCreate(['user_id' => $brand->id], ['currency' => 'USD', 'available_balance_cents' => 5000]);
        $brand->update(['referrer_id' => $contributor->id]);

        $config = [];
        foreach (['roles', 'permissions', 'permission_role', 'task_types', 'task_categories', 'task_templates', 'countries', 'email_templates', 'deposit_methods', 'withdrawal_rules', 'referral_rules'] as $table) {
            $config[$table] = DB::table($table)->count();
        }
        $this->assertGreaterThan(0, Campaign::count());

        (require database_path('migrations/2026_10_07_000003_reset_platform_data_keep_test_logins.php'))->up();

        // Only the test logins (+ every superadmin) remain, ready to sign in.
        $emails = User::orderBy('email')->pluck('email')->all();
        $this->assertEqualsCanonicalizing(
            ['admin@ebizearn.com', 'brand@ebizearn.com', 'sarah@ebizearn.com', $super->email],
            $emails
        );
        $this->assertNull(User::find($extraAdmin->id));
        $this->assertNull($brand->fresh()->referrer_id);
        $this->assertSame(1, DB::table('businesses')->count());

        // All activity data is gone.
        foreach (['tasks', 'task_assignments', 'task_submissions', 'submission_files', 'ai_verification_results', 'fraud_events',
            'campaigns', 'wallet_transactions', 'withdrawal_requests', 'deposit_requests', 'referrals', 'referral_rewards',
            'support_tickets', 'support_messages', 'audit_logs', 'personal_access_tokens'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty");
        }
        $this->assertFalse(Storage::disk('public')->exists('proofs/old.png'));
        $this->assertFalse(Storage::disk('local')->exists('support/1/a.txt'));

        // Configuration is untouched; wallets are fresh; no orphans.
        foreach ($config as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} should be kept");
        }
        $this->assertSame(0, (int) Wallet::sum('available_balance_cents'));
        $userIds = User::pluck('id');
        foreach (['profiles' => 'user_id', 'wallets' => 'user_id', 'permission_user' => 'user_id', 'businesses' => 'owner_id'] as $table => $col) {
            $this->assertSame(0, DB::table($table)->whereNotIn($col, $userIds)->count(), "{$table} has orphans");
        }

        // Test logins work and new data can be created from scratch.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'brand@ebizearn.com', 'password' => 'password123', 'portal' => 'business'])->assertOk();

        $this->app['auth']->forgetGuards();
        Wallet::updateOrCreate(['user_id' => $brand->id], ['currency' => 'USD', 'available_balance_cents' => 100000]);
        Sanctum::actingAs($this->staff('admin'));
        $campaignId = $this->postJson('/api/v1/staff/campaigns', [
            'business_id' => $brand->business->id,
            'title' => 'First fresh campaign',
            'description' => 'After the reset',
            'category_id' => TaskCategory::firstOrFail()->id,
            'reward_per_task_cents' => 20,
            'task_type_key' => 'follow',
            'target_contributors_count' => 10,
            'instructions_markdown' => 'Follow and screenshot.',
        ])->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/staff/tasks', $this->taskPayload(Campaign::findOrFail($campaignId)))->assertStatus(201);
    }

    public function test_reset_migration_refuses_when_no_account_would_be_kept(): void
    {
        User::query()->update(['email' => DB::raw("'x' || id || '@example.com'"), 'role' => 'contributor']);

        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_07_000003_reset_platform_data_keep_test_logins.php'))->up();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TaskCategory;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task Library templates (Super Admin managed, per-audience visibility) and
 * campaign edit / delete for staff and businesses, each gated on a
 * permission Super Admin can grant or revoke per role.
 */
class TaskLibraryAndCampaignEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Root',
            'email' => 'root' . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'superadmin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@ebizearn.com')->firstOrFail();
    }

    private function business(): User
    {
        return User::where('email', 'brand@acme.com')->firstOrFail();
    }

    private function fundedCampaign(): Campaign
    {
        $business = $this->business();
        Wallet::updateOrCreate(
            ['user_id' => $business->id],
            ['currency' => 'USD', 'available_balance_cents' => 100000, 'pending_balance_cents' => 0]
        );

        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/v1/staff/campaigns', [
            'business_id' => $business->business->id,
            'title' => 'Editable campaign',
            'description' => 'Original description',
            'category_id' => TaskCategory::firstOrFail()->id,
            'platform' => 'instagram',
            'reward_per_task_cents' => 20,
            'task_type_key' => 'follow',
            'target_contributors_count' => 10,
            'instructions_markdown' => 'Follow and screenshot.',
        ])->assertStatus(201)->json('data.id');

        return Campaign::findOrFail($id);
    }

    public function test_templates_are_seeded_and_filtered_by_audience(): void
    {
        $this->assertSame(8, TaskTemplate::count());

        TaskTemplate::where('name', 'App Testing')->update(['visible_to_business' => false]);
        TaskTemplate::where('name', 'YouTube Comment')->update(['visible_to_admin' => false]);
        TaskTemplate::where('name', 'Survey / Feedback Form')->update(['is_active' => false]);

        Sanctum::actingAs($this->business());
        $names = collect($this->getJson('/api/v1/business/task-templates')->assertOk()->json('data'))->pluck('name');
        $this->assertCount(6, $names);
        $this->assertNotContains('App Testing', $names);
        $this->assertNotContains('Survey / Feedback Form', $names);

        Sanctum::actingAs($this->admin());
        $names = collect($this->getJson('/api/v1/staff/task-templates')->assertOk()->json('data'))->pluck('name');
        $this->assertCount(6, $names);
        $this->assertNotContains('YouTube Comment', $names);

        // Super Admin manages, so sees everything.
        Sanctum::actingAs($this->superAdmin());
        $res = $this->getJson('/api/v1/staff/task-templates')->assertOk();
        $this->assertCount(8, $res->json('data'));
        $this->assertTrue($res->json('meta.can_manage'));
    }

    public function test_only_task_library_managers_can_write_templates(): void
    {
        $payload = ['name' => 'Facebook Group Post', 'description' => 'Share in a group.', 'icon' => 'megaphone'];

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/staff/task-templates', $payload)->assertStatus(403);

        Sanctum::actingAs($this->superAdmin());
        $id = $this->postJson('/api/v1/staff/task-templates', $payload)->assertStatus(201)->json('data.id');
        $this->patchJson("/api/v1/staff/task-templates/{$id}", ['visible_to_business' => false])
            ->assertOk()->assertJsonPath('data.visible_to_business', false);
        $this->deleteJson("/api/v1/staff/task-templates/{$id}")->assertOk();
        $this->assertNull(TaskTemplate::find($id));

        // Super Admin can grant the permission to the admin role.
        Role::where('name', 'admin')->firstOrFail()->permissions()
            ->attach(Permission::where('name', 'manage_task_library')->value('id'));
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/staff/task-templates', $payload)->assertStatus(201);
    }

    public function test_business_without_view_permission_cannot_see_library(): void
    {
        Role::where('name', 'business')->firstOrFail()->permissions()
            ->detach(Permission::where('name', 'view_task_library')->value('id'));

        Sanctum::actingAs($this->business());
        $this->getJson('/api/v1/business/task-templates')->assertStatus(403);
    }

    public function test_staff_can_edit_campaign_copy_but_not_money(): void
    {
        $campaign = $this->fundedCampaign();

        Sanctum::actingAs($this->admin());
        $this->patchJson("/api/v1/staff/campaigns/{$campaign->id}", [
            'title' => 'Renamed campaign',
            'description' => 'New description',
            'reward_per_task_cents' => 9999,
        ])->assertOk()->assertJsonPath('data.title', 'Renamed campaign');

        $campaign->refresh();
        $this->assertSame('New description', $campaign->description);
        $this->assertSame(20, (int) $campaign->reward_per_task_cents);
        // The task pool follows the campaign title.
        $this->assertSame('Renamed campaign', $campaign->tasks()->first()->title);
    }

    public function test_campaign_edit_and_delete_follow_role_permissions(): void
    {
        $campaign = $this->fundedCampaign();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $adminRole->permissions()->detach(Permission::whereIn('name', ['edit_campaigns', 'delete_campaigns'])->pluck('id'));

        Sanctum::actingAs($this->admin());
        $this->patchJson("/api/v1/staff/campaigns/{$campaign->id}", ['title' => 'x'])->assertStatus(403);
        $this->deleteJson("/api/v1/staff/campaigns/{$campaign->id}")->assertStatus(403);

        // Super Admin always can.
        Sanctum::actingAs($this->superAdmin());
        $this->patchJson("/api/v1/staff/campaigns/{$campaign->id}", ['title' => 'By super admin'])->assertOk();
    }

    public function test_deleting_untouched_funded_campaign_returns_escrow(): void
    {
        $campaign = $this->fundedCampaign();
        $wallet = Wallet::where('user_id', $this->business()->id)->firstOrFail();
        $this->assertSame(200, (int) $wallet->pending_balance_cents);

        Sanctum::actingAs($this->admin());
        $this->deleteJson("/api/v1/staff/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertJsonPath('data.escrow_released_cents', 200);

        $this->assertNull(Campaign::find($campaign->id));
        $wallet->refresh();
        $this->assertSame(0, (int) $wallet->pending_balance_cents);
        // Rewards came back; the 15% platform fee (30) stays debited.
        $this->assertSame(100000 - 30, (int) $wallet->available_balance_cents);
    }

    public function test_business_can_edit_and_delete_only_its_own_campaign(): void
    {
        $campaign = $this->fundedCampaign();

        $other = User::create([
            'name' => 'Other Biz',
            'email' => 'otherbiz@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'business',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        \App\Models\Business::create(['owner_id' => $other->id, 'company_name' => 'Other Co', 'status' => 'active']);

        Sanctum::actingAs($other);
        $this->patchJson("/api/v1/business/campaigns/{$campaign->id}", ['title' => 'Hijack'])->assertStatus(403);
        $this->deleteJson("/api/v1/business/campaigns/{$campaign->id}")->assertStatus(403);

        Sanctum::actingAs($this->business());
        $this->patchJson("/api/v1/business/campaigns/{$campaign->id}", ['title' => 'Mine, renamed'])
            ->assertOk()->assertJsonPath('data.title', 'Mine, renamed');
        $this->deleteJson("/api/v1/business/campaigns/{$campaign->id}")->assertOk();
        $this->assertNull(Campaign::find($campaign->id));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\User;
use App\Services\Tasks\NewTaskAnnouncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Business profile on the task page: real counts, follow / follow back,
 * the bell (new-task alerts), notifications, staff lists, manual KYC.
 */
class BusinessFollowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeUser(string $role, string $country = 'AE'): User
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
        Profile::forceCreate(['user_id' => $user->id, 'country_code' => $country]);

        return $user;
    }

    protected function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    protected function makeBusiness(): Business
    {
        $owner = $this->makeUser('business');

        return Business::forceCreate(['owner_id' => $owner->id, 'company_name' => 'Bean House', 'status' => 'active']);
    }

    protected function makeTask(Business $business, string $status = 'active', ?string $image = null): Task
    {
        $category = TaskCategory::forceCreate(['slug' => 'c-' . Str::random(6), 'name' => 'Social', 'is_active' => true]);
        $campaign = Campaign::forceCreate([
            'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'category_id' => $category->id,
            'title' => 'Coffee launch', 'description' => 'Post about our coffee', 'status' => $status,
            'platform' => 'instagram', 'target_countries_json' => ['ALL'],
            'total_budget_cents' => 10000, 'remaining_budget_cents' => 10000, 'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 100, 'target_contributors_count' => 50,
            'content_image_path' => $image,
        ]);

        return Task::forceCreate([
            'uuid' => (string) Str::uuid(), 'campaign_id' => $campaign->id, 'category_id' => $category->id,
            'title' => 'Follow us', 'status' => 'available', 'reward_cents' => 100, 'slots_total' => 50,
        ]);
    }

    public function test_profile_shows_real_counts_and_images_newest_first(): void
    {
        $business = $this->makeBusiness();
        $this->makeTask($business, 'active', 'campaign-content/old.png');
        $this->travel(1)->minutes();
        $this->makeTask($business, 'active', 'campaign-content/new.png');
        $this->makeTask($business, 'pending_review');

        $this->actAs($this->makeUser('contributor'));
        $res = $this->getJson("/api/v1/businesses/{$business->uuid}/profile")->assertOk();

        $res->assertJsonPath('data.name', 'Bean House')
            ->assertJsonPath('data.stats.posts', 3)
            ->assertJsonPath('data.stats.followers', 0)
            ->assertJsonPath('data.viewer.is_following', false);
        $this->assertStringEndsWith('new.png', $res->json('data.images.0.url'));
        $this->assertStringEndsWith('old.png', $res->json('data.images.1.url'));
    }

    public function test_follow_notifies_business_and_business_can_follow_back(): void
    {
        $business = $this->makeBusiness();
        $owner = $business->owner;
        $fan = $this->makeUser('contributor', 'IN');

        $this->actAs($fan);
        $this->postJson("/api/v1/businesses/{$business->uuid}/follow")->assertOk()
            ->assertJsonPath('data.stats.followers', 1)
            ->assertJsonPath('data.viewer.is_following', true);
        // Following twice doesn't double count or notify again.
        $this->postJson("/api/v1/businesses/{$business->uuid}/follow")->assertOk()->assertJsonPath('data.stats.followers', 1);

        $this->actAs($owner);
        $notes = $this->getJson('/api/v1/notifications')->assertOk();
        $notes->assertJsonPath('meta.unread', 1)->assertJsonPath('data.0.kind', 'new_follower');
        $this->assertStringContainsString($fan->name, $notes->json('data.0.body'));

        $this->getJson('/api/v1/business/followers')->assertOk()
            ->assertJsonPath('data.0.id', $fan->id)
            ->assertJsonPath('data.0.email', null)
            ->assertJsonPath('data.0.mutual', false);

        $this->postJson("/api/v1/business/following/{$fan->id}")->assertOk()->assertJsonPath('data.stats.following', 1);
        $this->getJson('/api/v1/business/followers')->assertJsonPath('data.0.mutual', true);

        // Can't follow back someone who doesn't follow you.
        $stranger = $this->makeUser('contributor');
        $this->postJson("/api/v1/business/following/{$stranger->id}")->assertStatus(422);

        $this->actAs($fan);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.kind', 'followed_back');
        $this->postJson('/api/v1/notifications/read')->assertOk()->assertJsonPath('data.unread', 0);
    }

    public function test_bell_controls_new_task_notifications(): void
    {
        $business = $this->makeBusiness();
        $bellOn = $this->makeUser('contributor');
        $bellOff = $this->makeUser('contributor');

        $this->actAs($bellOn);
        $this->postJson("/api/v1/businesses/{$business->uuid}/follow")->assertOk();
        $this->postJson("/api/v1/businesses/{$business->uuid}/alerts", ['enabled' => true])->assertOk()
            ->assertJsonPath('data.viewer.alerts_on', true);

        $this->actAs($bellOff);
        $this->postJson("/api/v1/businesses/{$business->uuid}/follow")->assertOk();
        $this->postJson("/api/v1/businesses/{$business->uuid}/alerts", ['enabled' => true])->assertOk();
        $this->postJson("/api/v1/businesses/{$business->uuid}/alerts", ['enabled' => false])->assertOk()
            ->assertJsonPath('data.viewer.alerts_on', false);

        $task = $this->makeTask($business);
        app(NewTaskAnnouncer::class)->processPending(10);

        $this->assertSame(1, $bellOn->notifications()->where('type', \App\Notifications\NewTaskFromBusiness::class)->count());
        $this->assertSame(0, $bellOff->notifications()->where('type', \App\Notifications\NewTaskFromBusiness::class)->count());
        $this->assertSame('/app/tasks/' . $task->uuid, $bellOn->notifications()->first()->data['link']);
    }

    public function test_staff_see_follow_lists_with_emails(): void
    {
        $business = $this->makeBusiness();
        $fan = $this->makeUser('contributor');
        $this->actAs($fan);
        $this->postJson("/api/v1/businesses/{$business->uuid}/follow")->assertOk();

        $this->actAs($this->makeUser('superadmin'));
        $this->getJson("/api/v1/admin/users/{$business->owner_id}")->assertOk()
            ->assertJsonPath('data.social.followers', 1)
            ->assertJsonPath('data.social.following', 0)
            ->assertJsonPath('data.social.posts', 0);
        $this->getJson("/api/v1/admin/users/{$business->owner_id}/follows?type=followers")->assertOk()
            ->assertJsonPath('data.0.email', $fan->email);
    }

    public function test_manual_kyc_approve_needs_the_permission(): void
    {
        $target = $this->makeUser('contributor');

        $this->actAs($this->makeUser('admin'));
        $this->postJson("/api/v1/staff/kyc/{$target->id}/manual-approve", ['note' => 'Verified on a video call'])->assertForbidden();

        $this->actAs($this->makeUser('superadmin'));
        $this->postJson("/api/v1/staff/kyc/{$target->id}/manual-approve", [])->assertStatus(422);
        $this->postJson("/api/v1/staff/kyc/{$target->id}/manual-approve", ['note' => 'Verified on a video call'])->assertOk();

        $this->assertSame('verified', $target->profile()->first()->kyc_status);
        $this->postJson("/api/v1/staff/kyc/{$target->id}/manual-approve", ['note' => 'Again please'])->assertStatus(422);
    }

    public function test_business_can_link_social_channels_for_staff_review(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business->owner);

        $id = $this->postJson('/api/v1/business/social-channels', ['platform' => 'instagram', 'profile_url' => '@beanhouse'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/business/social-channels/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending');

        $this->actAs($this->makeUser('superadmin'));
        $this->getJson('/api/v1/staff/social-channels?status=pending')->assertOk()
            ->assertJsonPath('data.0.user.role', 'business');
    }
}

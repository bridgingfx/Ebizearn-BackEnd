<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Post content contributors copy: manual (one approved text) or auto (each
 * contributor gets their own rewording of the approved text). Staff approve
 * content before the campaign's tasks are shown.
 */
class CampaignContentModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['services.openai.key' => 'test-key']);
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
        Wallet::firstOrCreate(['user_id' => $user->id], ['currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    protected function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    /** Active campaign with one available task and the given content state. */
    protected function makeTask(?string $mode, ?string $status, string $text = 'Fresh coffee every morning at Bean House! #coffee'): Task
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
            'generated_content' => $mode ? $text : null, 'content_mode' => $mode, 'content_status' => $status,
        ]);

        return Task::forceCreate([
            'uuid' => (string) Str::uuid(), 'campaign_id' => $campaign->id, 'category_id' => $category->id,
            'title' => 'Post on Instagram', 'status' => 'available', 'reward_cents' => 100, 'slots_total' => 50,
        ]);
    }

    public function test_tasks_stay_hidden_until_staff_approve_the_content(): void
    {
        $task = $this->makeTask('manual', 'pending');
        $contributor = $this->makeUser('contributor');
        $this->actAs($contributor);

        $this->getJson("/api/v1/tasks/{$task->uuid}")->assertNotFound();
        $this->postJson("/api/v1/tasks/{$task->uuid}/start")->assertStatus(400);

        $this->actAs($this->makeUser('admin'));
        $this->postJson("/api/v1/staff/campaigns/{$task->campaign_id}/content/decision", ['decision' => 'reject'])
            ->assertStatus(422); // a rejection needs a note for the business
        $this->postJson("/api/v1/staff/campaigns/{$task->campaign_id}/content/decision", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.content_status', 'approved');

        $this->actAs($contributor);
        $this->getJson("/api/v1/tasks/{$task->uuid}")->assertOk()
            ->assertJsonMissingPath('data.campaign.generated_content'); // handed out per contributor instead
        $this->postJson("/api/v1/tasks/{$task->uuid}/start")->assertCreated();
    }

    public function test_manual_mode_gives_everyone_the_approved_text(): void
    {
        $task = $this->makeTask('manual', 'approved');
        $contributor = $this->makeUser('contributor');
        $this->actAs($contributor);

        $this->getJson("/api/v1/tasks/{$task->uuid}/content")->assertForbidden(); // start first
        $this->postJson("/api/v1/tasks/{$task->uuid}/start")->assertCreated();
        $this->getJson("/api/v1/tasks/{$task->uuid}/content")->assertOk()
            ->assertJsonPath('data.mode', 'manual')
            ->assertJsonPath('data.content', 'Fresh coffee every morning at Bean House! #coffee');
    }

    public function test_auto_mode_gives_each_contributor_their_own_clean_version(): void
    {
        Http::fake([
            'api.openai.com/v1/moderations' => Http::response(['results' => [['flagged' => false, 'categories' => []]]]),
            'api.openai.com/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => 'Start your day with Bean House coffee! #coffee']]]])
                ->push(['choices' => [['message' => ['content' => 'Mornings taste better at Bean House. #coffee']]]]),
        ]);
        $task = $this->makeTask('auto', 'approved');

        $first = $this->makeUser('contributor');
        $this->actAs($first);
        $this->postJson("/api/v1/tasks/{$task->uuid}/start")->assertCreated();
        $this->getJson("/api/v1/tasks/{$task->uuid}/content")->assertOk()
            ->assertJsonPath('data.content', 'Start your day with Bean House coffee! #coffee')
            ->assertJsonPath('data.personal', true);
        // Same contributor, same text (kept on their assignment).
        $this->getJson("/api/v1/tasks/{$task->uuid}/content")->assertJsonPath('data.content', 'Start your day with Bean House coffee! #coffee');

        $second = $this->makeUser('contributor');
        $this->actAs($second);
        $this->postJson("/api/v1/tasks/{$task->uuid}/start")->assertCreated();
        $this->getJson("/api/v1/tasks/{$task->uuid}/content")->assertOk()
            ->assertJsonPath('data.content', 'Mornings taste better at Bean House. #coffee');

        $this->assertSame(2, TaskAssignment::whereNotNull('content')->count());
    }

    public function test_auto_mode_falls_back_to_the_approved_text_when_ai_is_down(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([], 500)]);
        $task = $this->makeTask('auto', 'approved');
        $this->actAs($this->makeUser('contributor'));

        $this->postJson("/api/v1/tasks/{$task->uuid}/start")->assertCreated();
        $this->getJson("/api/v1/tasks/{$task->uuid}/content")->assertOk()
            ->assertJsonPath('data.content', 'Fresh coffee every morning at Bean House! #coffee')
            ->assertJsonPath('data.personal', false);
    }

    public function test_staff_override_is_safety_checked_and_approved(): void
    {
        Http::fake(['api.openai.com/v1/moderations' => Http::response(['results' => [['flagged' => false, 'categories' => []]]])]);
        $task = $this->makeTask('manual', 'pending');
        $this->actAs($this->makeUser('admin'));

        $this->patchJson("/api/v1/staff/campaigns/{$task->campaign_id}/content", [
            'content_mode' => 'manual', 'generated_content' => 'Best damn shit coffee',
        ])->assertStatus(422);

        $this->patchJson("/api/v1/staff/campaigns/{$task->campaign_id}/content", [
            'content_mode' => 'auto', 'generated_content' => 'Great coffee at Bean House #coffee',
        ])->assertOk()
            ->assertJsonPath('data.content_mode', 'auto')
            ->assertJsonPath('data.content_status', 'approved');
    }
}

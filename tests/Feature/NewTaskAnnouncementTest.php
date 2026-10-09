<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\User;
use App\Services\Tasks\NewTaskAnnouncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * When a task goes live (campaign active + content approved + task
 * available) every eligible contributor gets one "new task" email.
 */
class NewTaskAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role, string $country = 'AE', array $extra = []): User
    {
        $user = User::forceCreate(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' ' . Str::random(4),
            'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ], $extra));
        Profile::forceCreate(['user_id' => $user->id, 'country_code' => $country]);

        return $user;
    }

    protected function makeTask(string $campaignStatus, array $countries = ['ALL']): Task
    {
        $owner = $this->makeUser('business');
        $business = Business::forceCreate(['owner_id' => $owner->id, 'company_name' => 'Bean House', 'status' => 'active']);
        $category = TaskCategory::forceCreate(['slug' => 'c-' . Str::random(6), 'name' => 'Social', 'is_active' => true]);
        $campaign = Campaign::forceCreate([
            'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'category_id' => $category->id,
            'title' => 'Coffee launch', 'description' => 'Post about our coffee', 'status' => $campaignStatus,
            'platform' => 'instagram', 'target_countries_json' => $countries,
            'total_budget_cents' => 10000, 'remaining_budget_cents' => 10000, 'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 150, 'target_contributors_count' => 50,
        ]);

        return Task::forceCreate([
            'uuid' => (string) Str::uuid(), 'campaign_id' => $campaign->id, 'category_id' => $category->id,
            'title' => 'Post on Instagram', 'status' => 'available', 'reward_cents' => 150, 'slots_total' => 50,
        ]);
    }

    protected function sentTo(): array
    {
        return EmailLog::where('event_key', NewTaskAnnouncer::EVENT_KEY)->pluck('to_email')->sort()->values()->all();
    }

    public function test_task_is_announced_once_to_eligible_contributors_when_campaign_goes_live(): void
    {
        $ae = $this->makeUser('contributor', 'AE');
        $in = $this->makeUser('contributor', 'IN');
        $this->makeUser('contributor', 'AE', ['email_verified_at' => null]);
        $this->makeUser('contributor', 'AE', ['marketing_unsubscribed_at' => now()]);
        $this->makeUser('contributor', 'AE', ['status' => 'suspended']);

        $task = $this->makeTask('pending_review');
        $this->assertNull($task->fresh()->announce_status, 'not live yet — nothing queued');

        $task->campaign->update(['status' => 'active']);
        $this->assertSame('pending', $task->fresh()->announce_status);

        app(NewTaskAnnouncer::class)->processPending(10);

        $this->assertSame(collect([$ae->email, $in->email])->sort()->values()->all(), $this->sentTo());
        $this->assertSame('done', $task->fresh()->announce_status);

        // Pause + resume never re-announces.
        $task->campaign->update(['status' => 'paused']);
        $task->campaign->update(['status' => 'active']);
        app(NewTaskAnnouncer::class)->processPending(10);
        $this->assertCount(2, $this->sentTo());
    }

    public function test_country_targeting_limits_the_audience(): void
    {
        $ae = $this->makeUser('contributor', 'AE');
        $this->makeUser('contributor', 'IN');

        $this->makeTask('active', ['AE']);
        app(NewTaskAnnouncer::class)->processPending(10);

        $this->assertSame([$ae->email], $this->sentTo());
    }

    public function test_email_has_task_details_and_open_task_link(): void
    {
        $this->makeUser('contributor');
        $task = $this->makeTask('active')->fresh(['campaign.business']);

        $template = EmailTemplate::where('event_key', NewTaskAnnouncer::EVENT_KEY)->firstOrFail();
        $vars = app(NewTaskAnnouncer::class)->taskVariables($task);

        $this->assertSame('$1.50', $vars['task_reward']);
        $this->assertSame('Bean House', $vars['brand_name']);
        $this->assertStringEndsWith('/app/tasks/' . $task->uuid, $vars['task_url']);
        $this->assertStringContainsString('{{task_url}}', $template->html_body);
        $this->assertStringContainsString('align="center"', $template->html_body);
    }

    public function test_disabled_template_sends_nothing(): void
    {
        $this->makeUser('contributor');
        EmailTemplate::where('event_key', NewTaskAnnouncer::EVENT_KEY)->update(['is_enabled' => false]);

        $task = $this->makeTask('active');
        app(NewTaskAnnouncer::class)->processPending(10);

        $this->assertSame([], $this->sentTo());
        $this->assertSame('skipped', $task->fresh()->announce_status);
    }
}

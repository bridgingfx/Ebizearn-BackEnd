<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Maintenance\DemoDataCleaner;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * cleanup:demo-data removes exactly the seeded demo data and keeps every
 * account, all configuration and every genuine campaign / payment.
 */
class DemoDataCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // testing env → seeds the demo data too
    }

    private function genuineCampaign(string $title): Campaign
    {
        $owner = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'name' => 'Real Biz', 'email' => 'real' . Str::random(5) . '@shop.com',
            'password' => Hash::make('V3r1fy!Strong'), 'role' => 'business', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $business = Business::create(['owner_id' => $owner->id, 'company_name' => 'Real Shop', 'status' => 'active']);
        $wallet = Wallet::firstOrCreate(['user_id' => $owner->id], ['currency' => 'USD']);
        (new WalletLedgerService())->credit($wallet, 5000, 'deposit', 'Real deposit');

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'category_id' => TaskCategory::firstOrFail()->id,
            'title' => $title, 'description' => 'Genuine campaign', 'status' => 'active',
            'total_budget_cents' => 1000, 'remaining_budget_cents' => 1000, 'reserved_budget_cents' => 0,
            'reward_per_task_cents' => 50, 'target_contributors_count' => 20,
        ]);
        Task::create(['uuid' => (string) Str::uuid(), 'campaign_id' => $campaign->id, 'category_id' => $campaign->category_id,
            'title' => 'Real task', 'reward_cents' => 50, 'slots_total' => 20, 'status' => 'available']);

        return $campaign;
    }

    public function test_dry_run_changes_nothing(): void
    {
        $before = Campaign::count();
        $this->artisan('cleanup:demo-data')->assertSuccessful();
        $this->assertSame($before, Campaign::count());
        $this->assertGreaterThan(0, Campaign::whereIn('title', DemoDataCleaner::DEMO_CAMPAIGN_TITLES)->count());
    }

    public function test_force_removes_only_demo_data(): void
    {
        $real = $this->genuineCampaign('Spring sale awareness');
        $marked = $this->genuineCampaign('Test campaign please ignore');
        $usersBefore = User::count();
        $categories = DB::table('task_categories')->count();
        $types = DB::table('task_types')->count();
        $templates = DB::table('task_templates')->count();

        $this->artisan('cleanup:demo-data --force')->assertSuccessful();

        // Demo gone.
        $this->assertSame(0, Campaign::whereIn('title', DemoDataCleaner::DEMO_CAMPAIGN_TITLES)->count());
        $this->assertSame(0, DB::table('task_submissions')->count());
        $sarah = User::where('email', DemoDataCleaner::DEMO_CONTRIBUTOR_EMAIL)->firstOrFail();
        $wallet = Wallet::where('user_id', $sarah->id)->firstOrFail();
        $this->assertSame(0, WalletTransaction::where('wallet_id', $wallet->id)->count());
        $this->assertSame(0, (int) $wallet->available_balance_cents);
        $this->assertSame(0, (int) $wallet->pending_balance_cents);
        $this->assertSame(0, (int) $sarah->profile->fresh()->completed_tasks_count);

        // Everything else kept.
        $this->assertSame($usersBefore, User::count());
        $this->assertNotNull(User::where('email', 'admin@ebizearn.com')->first());
        $this->assertNotNull(User::where('email', DemoDataCleaner::DEMO_BUSINESS_EMAIL)->first());
        $this->assertSame($categories, DB::table('task_categories')->count());
        $this->assertSame($types, DB::table('task_types')->count());
        $this->assertSame($templates, DB::table('task_templates')->count());
        $this->assertNotNull($real->fresh());
        $this->assertSame(1, Task::where('campaign_id', $real->id)->count());
        $this->assertNotNull($marked->fresh(), 'look-alike test data is only removed on request');
        $this->assertSame(1, WalletTransaction::where('description', 'Real deposit')->where('wallet_id', Wallet::where('user_id', $real->business->owner_id)->value('id'))->count());

        // Opt-in removal of look-alike test data.
        $this->artisan('cleanup:demo-data --include-marked --force')->assertSuccessful();
        $this->assertNull($marked->fresh());
        $this->assertNotNull($real->fresh());
    }

    public function test_seeder_does_not_create_demo_data_outside_tests(): void
    {
        $this->artisan('cleanup:demo-data --force');
        $this->app['env'] = 'production';

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Campaign::whereIn('title', DemoDataCleaner::DEMO_CAMPAIGN_TITLES)->count());
        $sarah = User::where('email', DemoDataCleaner::DEMO_CONTRIBUTOR_EMAIL)->firstOrFail();
        $this->assertSame(0, (int) Wallet::where('user_id', $sarah->id)->value('available_balance_cents'));
        $this->assertSame(0, WalletTransaction::count());
    }
}

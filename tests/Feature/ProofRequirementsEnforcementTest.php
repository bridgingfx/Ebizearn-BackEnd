<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\TaskCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proof-requirement enforcement.
 *
 * The fraud screen enforces the task's proof contract (screenshot / url /
 * text). The canonical storage format is a plain list of requirement names.
 * Legacy rows (and legacy API clients) stored an assoc map
 * (['screenshot' => true]); iterating the VALUES of that map made the
 * match() see only booleans and every requirement silently passed. The
 * screen now normalizes assoc maps, and new campaigns store the list
 * format — so a missing screenshot is a 422, not an accepted submission.
 */
class ProofRequirementsEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function businessUser(): User
    {
        $this->seed();

        return User::where('email', 'brand@ebizearn.com')->firstOrFail();
    }

    private function makeCampaign(User $business): Campaign
    {
        return Campaign::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->business->id,
            'category_id' => TaskCategory::firstOrFail()->id,
            'title' => 'Proof requirements campaign ' . Str::random(6),
            'description' => 'Proof requirements test.',
            'status' => 'active',
            'total_budget_cents' => 10000,
            'reward_per_task_cents' => 15,
            'target_contributors_count' => 10,
            'remaining_budget_cents' => 10000,
            'reserved_budget_cents' => 0,
        ]);
    }

    private function makeContributor(): User
    {
        return User::create([
            'name' => 'Proof Contributor',
            'email' => 'proof-con-' . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_legacy_assoc_proof_requirements_still_demand_a_screenshot(): void
    {
        $business = $this->businessUser();
        $campaign = $this->makeCampaign($business);

        Sanctum::actingAs($business);

        // A legacy-style assoc map must behave like ['screenshot'].
        $taskResp = $this->postJson('/api/v1/business/tasks', [
            'campaign_id' => $campaign->id,
            'task_type_key' => 'follow',
            'title' => 'Assoc proof task',
            'reward_cents' => 15,
            'slots_total' => 5,
            'proof_required' => ['screenshot' => true],
        ]);
        $taskResp->assertStatus(201);
        $taskId = $taskResp->json('data.id');
        $this->assertNotEmpty($taskId);

        Sanctum::actingAs($this->makeContributor());

        // Text-only proof: the screenshot requirement must fire.
        $response = $this->postJson("/api/v1/tasks/{$taskId}/submit", [
            'text_answer' => 'did it ' . Str::random(8),
        ]);

        $response->assertStatus(422);
        $this->assertSame('missing_requirements', $response->json('reason_code'));
        $this->assertDatabaseMissing('task_submissions', ['task_id' => $taskId]);
    }

    public function test_list_format_proof_requirements_demand_screenshot_and_url(): void
    {
        $business = $this->businessUser();
        $campaign = $this->makeCampaign($business);

        Sanctum::actingAs($business);

        $taskResp = $this->postJson('/api/v1/business/tasks', [
            'campaign_id' => $campaign->id,
            'task_type_key' => 'share', // contract: ['screenshot', 'url']
            'title' => 'List proof task',
            'reward_cents' => 15,
            'slots_total' => 5,
            'proof_required' => ['screenshot', 'url'],
        ]);
        $taskResp->assertStatus(201);
        $taskId = $taskResp->json('data.id');

        Sanctum::actingAs($this->makeContributor());

        // Screenshot only: the URL requirement must fire.
        $response = $this->postJson("/api/v1/tasks/{$taskId}/submit", [
            'proof_screenshot' => 'data:image/png;base64,' . base64_encode('fake-bytes-' . Str::random(32)),
        ]);

        $response->assertStatus(422);
        $this->assertSame('missing_requirements', $response->json('reason_code'));
    }

    public function test_legacy_campaign_store_default_is_list_format(): void
    {
        $business = $this->businessUser();

        $wallet = \App\Models\Wallet::firstOrCreate(
            ['user_id' => $business->id],
            ['currency' => 'USD', 'available_balance_cents' => 0]
        );
        $wallet->update(['available_balance_cents' => 100000, 'pending_balance_cents' => 0]);

        Sanctum::actingAs($business);

        $response = $this->postJson('/api/v1/business/campaigns', [
            'title' => 'Proof format campaign',
            'description' => 'Checks the default proof-requirements format.',
            'category_id' => TaskCategory::firstOrFail()->id,
            'task_type_key' => 'follow',
            'reward_per_task_cents' => 20, // endpoint floor is 20¢; follow band is 10–20¢
            'target_contributors_count' => 5,
            'instructions_markdown' => 'Follow and screenshot.',
        ]);

        $response->assertStatus(201);

        $campaign = Campaign::findOrFail($response->json('data.id'));
        $this->assertSame(['screenshot', 'url'], $campaign->proof_requirements_json);
    }
}

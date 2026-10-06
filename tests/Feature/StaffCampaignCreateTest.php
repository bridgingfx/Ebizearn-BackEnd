<?php

namespace Tests\Feature;

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
 * Staff campaign creation (admin posting on behalf of a business).
 *
 * Before this feature, POST /staff/campaigns did not exist — the admin
 * panel could pause/cancel campaigns but could never post a new one; only
 * businesses could. Now staff with the manage_task_templates permission
 * can create a campaign for any business through the exact same pipeline
 * as the business portal:
 *
 * - reward must sit inside the task type's band (honest 422 otherwise)
 * - the TARGET business owner's wallet must cover rewards + platform fee
 *   (P0 funding gate — the admin can never create money from nothing)
 * - escrow hold + platform fee debit happen atomically
 * - funded campaigns park in pending_review for approval
 * - every staff creation is audit-logged
 */
class StaffCampaignCreateTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        $this->seed();

        return User::create([
            'name' => 'Campaign Poster',
            'email' => 'poster' . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'moderator', // seeder grants moderator manage_task_templates
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function fundedBusiness(): User
    {
        $business = User::where('email', 'brand@ebizearn.com')->firstOrFail();

        Wallet::updateOrCreate(
            ['user_id' => $business->id],
            ['currency' => 'USD', 'available_balance_cents' => 100000, 'pending_balance_cents' => 0]
        );

        return $business;
    }

    private function payload(int $businessId): array
    {
        return [
            'business_id' => $businessId,
            'title' => 'Staff-posted campaign ' . Str::random(6),
            'description' => 'Posted by staff on behalf of the business.',
            'category_id' => TaskCategory::firstOrFail()->id,
            'platform' => 'instagram',
            'reward_per_task_cents' => 20,
            'task_type_key' => 'follow',
            'target_contributors_count' => 10,
            'instructions_markdown' => 'Follow the account and submit a screenshot.',
        ];
    }

    public function test_staff_can_post_a_campaign_for_a_business(): void
    {
        $staff = $this->staffUser();
        $business = $this->fundedBusiness();

        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/v1/staff/campaigns', $this->payload($business->business->id));

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $campaignId = $response->json('data.id');
        $campaign = Campaign::findOrFail($campaignId);

        $this->assertSame($business->business->id, $campaign->business_id);
        $this->assertSame('pending_review', $campaign->status);
        $this->assertSame(1, $campaign->tasks()->count());

        // P0 funding gate: rewards escrowed (200 cents) + 15% platform fee
        // debited from the BUSINESS wallet, not the staff member's.
        $wallet = Wallet::where('user_id', $business->id)->firstOrFail();
        $this->assertSame(100000 - 200 - 30, (int) $wallet->available_balance_cents);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'campaign.created_by_staff',
            'entity_type' => Campaign::class,
            'entity_id' => $campaign->id,
        ]);
    }

    public function test_staff_campaign_is_rejected_when_business_wallet_is_short(): void
    {
        $staff = $this->staffUser();
        $business = User::where('email', 'brand@ebizearn.com')->firstOrFail();

        Wallet::updateOrCreate(
            ['user_id' => $business->id],
            ['currency' => 'USD', 'available_balance_cents' => 10, 'pending_balance_cents' => 0]
        );

        Sanctum::actingAs($staff);

        $before = Campaign::count();
        $response = $this->postJson('/api/v1/staff/campaigns', $this->payload($business->business->id));

        $response->assertStatus(422);
        $this->assertSame($before, Campaign::count());
    }

    public function test_staff_campaign_requires_a_business(): void
    {
        $staff = $this->staffUser();

        Sanctum::actingAs($staff);

        $payload = $this->payload(999999);
        $response = $this->postJson('/api/v1/staff/campaigns', $payload);
        $response->assertStatus(422);

        unset($payload['business_id']);
        $response = $this->postJson('/api/v1/staff/campaigns', $payload);
        $response->assertStatus(422);
    }

    public function test_contributor_cannot_post_a_campaign(): void
    {
        $this->seed();
        $business = $this->fundedBusiness();

        $contributor = User::create([
            'name' => 'Sneaky Contributor',
            'email' => 'sneaky' . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($contributor);

        $before = Campaign::count();
        $this->postJson('/api/v1/staff/campaigns', $this->payload($business->business->id))
            ->assertStatus(403);

        $this->assertSame($before, Campaign::count());
    }
}

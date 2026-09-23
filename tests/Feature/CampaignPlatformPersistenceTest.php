<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\TaskCategory;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Round 3 — campaign wizard platform persistence.
 *
 * The wizard collects a platform in step 2. These tests prove the value
 * survives the round trip:
 * - POST /business/campaigns (the endpoint the wizard UI calls): platform
 *   is accepted, stored on the campaign row, and copied onto the
 *   materialized task pool.
 * - POST /business/campaigns/wizard/draft: platform is stored on the draft
 *   row (not only inside the wizard-answers JSON stash), and survives
 *   draft -> launch onto the task pool.
 * - platform stays optional; over-long values are rejected with 422.
 */
class CampaignPlatformPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeBusiness(string $email): User
    {
        $user = User::create([
            'name' => 'Biz ' . $email,
            'email' => $email,
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'business',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Business::create(['owner_id' => $user->id, 'company_name' => 'Co ' . $email, 'status' => 'active']);
        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    protected function fundBusiness(User $biz, int $cents): void
    {
        $wallet = Wallet::where('user_id', $biz->id)->firstOrFail();
        app(WalletLedgerService::class)->credit($wallet, $cents, 'campaign_funding', 'Test top-up');
    }

    protected function storePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Platform Test Campaign ' . Str::random(6),
            'description' => 'A campaign that names its platform.',
            'category_id' => TaskCategory::firstOrFail()->id,
            'task_type_key' => 'follow', // band 10–20¢
            'platform' => 'TikTok',
            'reward_per_task_cents' => 20,
            'target_contributors_count' => 5,
            'instructions_markdown' => 'Follow the account and keep the follow.',
        ], $overrides);
    }

    public function test_store_persists_platform_on_campaign_and_task_pool(): void
    {
        $biz = $this->makeBusiness('plat1@example.com');
        $this->fundBusiness($biz, 10000);
        Sanctum::actingAs($biz);

        $response = $this->postJson('/api/v1/business/campaigns', $this->storePayload());

        $response->assertStatus(201)->assertJson(['success' => true]);

        $campaign = Campaign::findOrFail($response->json('data.id'));
        $this->assertSame('TikTok', $campaign->platform);

        // The contributor-facing task pool carries the platform too.
        $task = $campaign->tasks()->firstOrFail();
        $this->assertSame('TikTok', $task->platform);

        // And it is visible through the API response.
        $this->assertSame('TikTok', $response->json('data.platform'));
    }

    public function test_store_platform_is_optional(): void
    {
        $biz = $this->makeBusiness('plat2@example.com');
        $this->fundBusiness($biz, 10000);
        Sanctum::actingAs($biz);

        $payload = $this->storePayload();
        unset($payload['platform']);

        $response = $this->postJson('/api/v1/business/campaigns', $payload);

        $response->assertStatus(201);
        $this->assertNull(Campaign::findOrFail($response->json('data.id'))->platform);
    }

    public function test_store_rejects_overlong_platform(): void
    {
        $biz = $this->makeBusiness('plat3@example.com');
        $this->fundBusiness($biz, 10000);
        Sanctum::actingAs($biz);

        $response = $this->postJson('/api/v1/business/campaigns', $this->storePayload([
            'platform' => str_repeat('x', 65),
        ]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('platform', $response->json('errors'));
    }

    public function test_draft_persists_platform_on_the_row_and_launch_carries_it_to_tasks(): void
    {
        $biz = $this->makeBusiness('plat4@example.com');
        $this->fundBusiness($biz, 10000);
        Sanctum::actingAs($biz);

        $draftId = $this->postJson('/api/v1/business/campaigns/wizard/draft', [
            'title' => 'Draft platform ' . Str::random(6),
            'category_id' => TaskCategory::firstOrFail()->id,
            'task_type_key' => 'follow',
            'platform' => 'instagram',
            'instructions' => 'Follow and keep the follow for 7 days.',
            'reward_cents' => 20,
            'contributors' => 10,
        ])->assertStatus(201)->json('data.id');

        // First-class column, not only the JSON stash.
        $this->assertSame('instagram', Campaign::findOrFail($draftId)->platform);

        $this->postJson("/api/v1/business/campaigns/{$draftId}/launch", [])
            ->assertStatus(200);

        $task = Campaign::findOrFail($draftId)->tasks()->firstOrFail();
        $this->assertSame('instagram', $task->platform);
    }

    protected function makeDraft(User $biz, array $overrides = []): int
    {
        return $this->postJson('/api/v1/business/campaigns/wizard/draft', array_merge([
            'title' => 'Editable draft ' . Str::random(6),
            'category_id' => TaskCategory::firstOrFail()->id,
            'task_type_key' => 'follow',
            'platform' => 'instagram',
            'instructions' => 'Follow and keep the follow.',
            'reward_cents' => 20,
            'contributors' => 10,
        ], $overrides))->assertStatus(201)->json('data.id');
    }

    public function test_update_draft_persists_edits_including_platform(): void
    {
        $biz = $this->makeBusiness('plat5@example.com');
        Sanctum::actingAs($biz);
        $draftId = $this->makeDraft($biz);

        $response = $this->patchJson("/api/v1/business/campaigns/wizard/draft/{$draftId}", [
            'title' => 'Edited draft title',
            'category_id' => TaskCategory::firstOrFail()->id,
            'task_type_key' => 'share', // band 10–30¢
            'platform' => 'TikTok',
            'instructions' => 'Share the post.',
            'reward_cents' => 25,
            'contributors' => 20,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $draft = Campaign::findOrFail($draftId);
        $this->assertSame('Edited draft title', $draft->title);
        $this->assertSame('TikTok', $draft->platform);
        $this->assertSame(25, (int) $draft->reward_per_task_cents);
        $this->assertSame(20, (int) $draft->target_contributors_count);
        $this->assertSame('share', $draft->proof_requirements_json['wizard']['task_type_key'] ?? null);
        $this->assertSame('TikTok', $draft->proof_requirements_json['wizard']['platform'] ?? null);
        $this->assertSame('draft', $draft->status);
    }

    public function test_update_draft_rejected_once_launched(): void
    {
        $biz = $this->makeBusiness('plat6@example.com');
        $this->fundBusiness($biz, 10000);
        Sanctum::actingAs($biz);
        $draftId = $this->makeDraft($biz);

        $this->postJson("/api/v1/business/campaigns/{$draftId}/launch", [])->assertStatus(200);

        $this->patchJson("/api/v1/business/campaigns/wizard/draft/{$draftId}", [
            'title' => 'Too late',
            'category_id' => TaskCategory::firstOrFail()->id,
            'task_type_key' => 'follow',
            'reward_cents' => 20,
            'contributors' => 10,
        ])->assertStatus(422);

        $this->assertNotSame('Too late', Campaign::findOrFail($draftId)->title);
    }

    public function test_update_draft_forbidden_cross_business(): void
    {
        $bizA = $this->makeBusiness('plat7a@example.com');
        $bizB = $this->makeBusiness('plat7b@example.com');
        Sanctum::actingAs($bizA);
        $draftId = $this->makeDraft($bizA);

        Sanctum::actingAs($bizB);
        $this->patchJson("/api/v1/business/campaigns/wizard/draft/{$draftId}", [
            'title' => 'Hijacked',
            'category_id' => TaskCategory::firstOrFail()->id,
            'task_type_key' => 'follow',
            'reward_cents' => 20,
            'contributors' => 10,
        ])->assertStatus(403);
    }

    public function test_update_draft_enforces_reward_bands(): void
    {
        $biz = $this->makeBusiness('plat8@example.com');
        Sanctum::actingAs($biz);
        $draftId = $this->makeDraft($biz);

        $this->patchJson("/api/v1/business/campaigns/wizard/draft/{$draftId}", [
            'title' => 'Band breaker',
            'category_id' => TaskCategory::firstOrFail()->id,
            'task_type_key' => 'follow', // band 10–20¢
            'reward_cents' => 500,
            'contributors' => 10,
        ])->assertStatus(422);

        $this->assertSame(20, (int) Campaign::findOrFail($draftId)->reward_per_task_cents);
    }
}

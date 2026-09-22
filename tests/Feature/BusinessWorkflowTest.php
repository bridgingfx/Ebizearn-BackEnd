<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Priority 4 — Business workflow E2E (Phase 2).
 *
 * Proves the complete business flow with real backend data:
 *  1. Business registration via API
 *  2. Business login via API (real token)
 *  3. Campaign draft via wizard with all fields (title, company, platform,
 *     instructions, reward, participant limit, proof requirements, retention)
 *  4. Campaign logo upload (validated image, stored on disk)
 *  5. Campaign launch → pending_review (approval gate)
 *  6. Task INVISIBLE in contributor feed before approval
 *  7. Admin approval → active
 *  8. Task VISIBLE in contributor feed after approval
 */
class BusinessWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function fundBusiness(User $biz, int $cents): void
    {
        $wallet = Wallet::where('user_id', $biz->id)->firstOrFail();
        app(WalletLedgerService::class)->credit($wallet, $cents, 'campaign_funding', 'Test top-up');
    }

    protected function loginAndGetToken(string $email, string $portal): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password123',
            'portal' => $portal,
        ]);
        $response->assertStatus(200);
        return $response->json('data.token');
    }

    public function test_full_business_campaign_flow(): void
    {
        $this->seed();
        Storage::fake('public');

        // 1. Business registration via API
        $email = 'biz' . Str::random(6) . '@example.com';
        $reg = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Business',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'business',
            'company_name' => 'Test Co LLC',
        ]);
        $reg->assertStatus(201);
        $this->assertEquals('business', $reg->json('data.user.role'));

        $business = User::where('email', $email)->firstOrFail();
        $this->assertNotNull($business->business);
        $this->assertEquals('Test Co LLC', $business->business->company_name);

        // 2. Business login via API (real token)
        $token = $this->loginAndGetToken($email, 'business');
        $auth = ['Authorization' => 'Bearer ' . $token];

        // Fund the business wallet so launch can reserve escrow.
        $this->fundBusiness($business, 10000);

        // 3. Campaign draft via wizard with all required fields
        $category = TaskCategory::firstOrCreate(
            ['slug' => 'social-media'],
            ['name' => 'Social Media', 'is_active' => true, 'sort_order' => 1]
        );
        $categoryId = $category->id;
        $draft = $this->withHeaders($auth)->postJson('/api/v1/business/campaigns/wizard/draft', [
            'title' => 'Follow our Instagram',
            'task_title' => 'Follow @testco on Instagram',
            'objective' => 'Grow Instagram audience',
            'description' => 'Help us grow our social presence',
            'category_id' => $categoryId,
            'task_type_key' => 'follow',
            'platform' => 'instagram',
            'country_code' => 'AE',
            'instructions' => 'Follow @testco and keep the follow for 7 days. Screenshot your following list.',
            'proof_requirements' => ['screenshot', 'username'],
            'reward_cents' => 20,
            'contributors' => 10,
            'retention_days' => 7,
            'estimated_minutes' => 3,
            'difficulty' => 'easy',
            'countries' => ['AE'],
            'languages' => ['en'],
        ]);
        $draft->assertStatus(201);
        $campaignId = $draft->json('data.id');
        $this->assertNotEmpty($campaignId);

        // 4. Campaign logo upload (validated image, stored on disk)
        // Note: GD not available, so craft a minimal valid PNG manually.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $tmpLogo = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        file_put_contents($tmpLogo, $png);
        $logo = $this->withHeaders($auth)->post(
            "/api/v1/business/campaigns/{$campaignId}/logo",
            [
                'logo' => new \Illuminate\Http\UploadedFile($tmpLogo, 'logo.png', 'image/png', null, true),
                'company_name' => 'Test Co LLC',
            ]
        );
        @unlink($tmpLogo);
        $logo->assertStatus(200);
        $logoPath = $logo->json('data.logo_path');
        $this->assertNotEmpty($logoPath);
        Storage::disk('public')->assertExists($logoPath);

        $campaign = Campaign::findOrFail($campaignId);
        $this->assertEquals($logoPath, $campaign->logo_path);
        $this->assertEquals('Test Co LLC', $campaign->company_name);

        // 5. Campaign launch → pending_review (approval gate)
        $launch = $this->withHeaders($auth)->postJson("/api/v1/business/campaigns/{$campaignId}/launch", []);
        $launch->assertStatus(200);

        $campaign = $campaign->fresh();
        $this->assertEquals('pending_review', $campaign->status);

        // Escrow was reserved.
        $wallet = Wallet::where('user_id', $business->id)->firstOrFail();
        $this->assertGreaterThan(0, $wallet->pending_balance_cents);

        // 6. Task INVISIBLE in contributor feed before approval
        $contribEmail = 'contrib' . Str::random(6) . '@example.com';
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Feed Checker',
            'email' => $contribEmail,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'contributor',
        ])->assertStatus(201);
        $contribToken = $this->loginAndGetToken($contribEmail, 'contributor');

        $feedBefore = $this->withHeaders(['Authorization' => 'Bearer ' . $contribToken])
            ->getJson('/api/v1/tasks');
        $feedBefore->assertStatus(200);
        $taskIdsBefore = collect($feedBefore->json('data'))->pluck('id')->all();
        $task = Task::where('campaign_id', $campaignId)->firstOrFail();
        $this->assertNotContains($task->id, $taskIdsBefore);

        // 7. Admin approval → active
        // Note: seeder already granted moderator role the manage_task_templates permission.
        $admin = User::create([
            'name' => 'Staff Approver',
            'email' => 'approver@example.com',
            'password' => Hash::make('password123'),
            'role' => 'moderator',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $approve = $this->patchJson("/api/v1/staff/campaigns/{$campaignId}/status", [
            'status' => 'active',
        ]);
        $approve->assertStatus(200);
        $this->assertEquals('active', Campaign::findOrFail($campaignId)->status);

        // Audit log written.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'campaign.status_changed',
            'entity_type' => Campaign::class,
            'entity_id' => $campaignId,
        ]);

        // 8. Task VISIBLE in contributor feed after approval
        $feedAfter = $this->withHeaders(['Authorization' => 'Bearer ' . $contribToken])
            ->getJson('/api/v1/tasks');
        $feedAfter->assertStatus(200);
        $taskIdsAfter = collect($feedAfter->json('data'))->pluck('id')->all();
        $this->assertContains($task->id, $taskIdsAfter);
    }
}

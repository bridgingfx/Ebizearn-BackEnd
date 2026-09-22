<?php

namespace Tests\Feature;

use App\Models\TaskCategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Escrow funding gate (P0): campaigns must be fully funded before going
 * active. Underfunded creation is rejected with an honest 422; funded
 * creation moves the rewards budget into escrow (available -> pending).
 */
class EscrowFundingGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function businessPayload(): array
    {
        $category = TaskCategory::firstOrFail();

        return [
            'title' => 'Escrow Gate Test Campaign',
            'description' => 'A campaign that must not go active without funding.',
            'category_id' => $category->id,
            'task_type_key' => 'survey', // band 20–200¢ covers the $1.00 reward below
            'reward_per_task_cents' => 100, // $1.00
            'target_contributors_count' => 5,
            'instructions_markdown' => 'Do the thing.',
        ];
    }

    public function test_campaign_creation_rejected_when_wallet_cannot_cover_budget(): void
    {
        $business = User::where('email', 'brand@acme.com')->firstOrFail();

        // Empty wallet: $0 available. Campaign needs $5.00 tasks + 15% fee.
        Wallet::firstOrCreate(
            ['user_id' => $business->id],
            ['currency' => 'USD', 'available_balance_cents' => 0, 'pending_balance_cents' => 0]
        );

        Sanctum::actingAs($business);

        $response = $this->postJson('/api/v1/business/campaigns', $this->businessPayload());

        $response->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertStringContainsString(
            'Insufficient funded balance.',
            (string) $response->json('message')
        );

        // Nothing may be left behind: no campaign rows at all for this title.
        $this->assertDatabaseMissing('campaigns', ['title' => 'Escrow Gate Test Campaign']);
    }

    public function test_campaign_creation_holds_escrow_and_goes_active_when_funded(): void
    {
        $business = User::where('email', 'brand@acme.com')->firstOrFail();

        // Fund the wallet: $100.00 available.
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $business->id],
            ['currency' => 'USD', 'available_balance_cents' => 0, 'pending_balance_cents' => 0]
        );
        $wallet->update(['available_balance_cents' => 10000, 'pending_balance_cents' => 0]);

        Sanctum::actingAs($business);

        $response = $this->postJson('/api/v1/business/campaigns', $this->businessPayload());

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            // Priority 4 — approval gate: funded campaigns park in pending_review.
            ->assertJsonPath('data.status', 'pending_review');

        // Tasks budget = 5 x 100 = 500 cents held in escrow; platform fee 15%
        // = 75 cents debited immediately (non-refundable).
        $wallet = $wallet->fresh();
        $this->assertEquals(10000 - 500 - 75, $wallet->available_balance_cents);
        $this->assertEquals(500, $wallet->pending_balance_cents);

        $campaignId = $response->json('data.id');
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'campaign_funding',
            'amount_cents' => -500,
        ]);
        $this->assertDatabaseHas('campaigns', ['id' => $campaignId, 'status' => 'pending_review']);
    }
}

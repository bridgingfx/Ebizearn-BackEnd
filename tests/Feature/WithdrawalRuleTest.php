<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRule;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2: withdrawal minimum is DB-backed and Super-Admin-selectable
 * ($10/$25/$50/$100). Every enforcement path — controller validation and
 * the ledger service — reads the active rule.
 */
class WithdrawalRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeContributorWithBalance(int $cents): User
    {
        $user = User::create([
            'name' => 'Wally',
            'email' => 'wally@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'available_balance_cents' => $cents,
        ]);

        return $user;
    }

    public function test_crypto_payout_method_is_rejected_in_mvp(): void
    {
        $user = $this->makeContributorWithBalance(10000);

        $this->actingAs($user)
            ->postJson('/api/v1/wallet/withdraw', [
                'amount_cents' => 10000,
                'payout_method' => 'crypto',
                'payout_details' => ['address' => '0xabc'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.payout_method.0', fn ($msg) => str_contains($msg, 'selected') || str_contains($msg, 'invalid'));
    }

    public function test_default_minimum_is_50_usd(): void
    {
        $this->assertSame(5000, WithdrawalRule::currentMinCents());
    }

    public function test_withdrawal_below_active_minimum_is_rejected(): void
    {
        $user = $this->makeContributorWithBalance(10000);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/wallet/withdraw', [
            'amount_cents' => 2000,
            'payout_method' => 'bank_transfer',
            'payout_details' => ['account' => '123'],
        ])->assertStatus(422);
    }

    public function test_superadmin_can_change_active_minimum_and_it_takes_effect(): void
    {
        $super = User::create([
            'name' => 'Ops',
            'email' => 'ops-wd@example.com',
            'password' => Hash::make('supersecretpassword123'),
            'role' => 'superadmin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($super);

        $rule = WithdrawalRule::where('amount_cents', 1000)->firstOrFail();

        $response = $this->postJson("/api/v1/ops/withdrawal-rules/{$rule->id}/activate");
        $response->assertStatus(200)->assertJsonPath('data.active_min_cents', 1000);

        $this->assertSame(1000, WithdrawalRule::currentMinCents());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.withdrawal_rule_activated',
            'entity_type' => WithdrawalRule::class,
            'entity_id' => $rule->id,
        ]);

        // A non-superadmin cannot change it
        $contributor = User::factory()->create(['role' => 'contributor']);
        Sanctum::actingAs($contributor);
        $this->postJson("/api/v1/ops/withdrawal-rules/{$rule->id}/activate")->assertStatus(403);
    }

    public function test_lowered_minimum_allows_smaller_withdrawal(): void
    {
        WithdrawalRule::activate(WithdrawalRule::where('amount_cents', 1000)->firstOrFail()->id);

        $user = $this->makeContributorWithBalance(10000);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/wallet/withdraw', [
            'amount_cents' => 1500,
            'payout_method' => 'bank_transfer',
            'payout_details' => ['account' => '123'],
        ])->assertStatus(201);

        $this->assertSame(8500, $user->wallet->fresh()->available_balance_cents);
    }

    public function test_ledger_service_enforces_active_minimum_directly(): void
    {
        $user = $this->makeContributorWithBalance(10000);
        $service = app(WalletLedgerService::class);

        try {
            $service->requestWithdrawal($user, 2000, 'bank_transfer', ['account' => '123']);
            $this->fail('Expected minimum-withdrawal exception.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Minimum withdrawal', $e->getMessage());
        }

        WithdrawalRule::activate(WithdrawalRule::where('amount_cents', 2500)->firstOrFail()->id);

        // 2000 still below 2500
        try {
            $service->requestWithdrawal($user, 2000, 'bank_transfer', ['account' => '123']);
            $this->fail('Expected minimum-withdrawal exception.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Minimum withdrawal', $e->getMessage());
        }

        $withdrawal = $service->requestWithdrawal($user, 3000, 'bank_transfer', ['account' => '123']);
        $this->assertSame(3000, $withdrawal->amount_cents);
    }

    public function test_only_one_rule_is_active_at_a_time(): void
    {
        $this->assertSame(1, WithdrawalRule::where('is_active', true)->count());

        WithdrawalRule::activate(WithdrawalRule::where('amount_cents', 10000)->firstOrFail()->id);

        $this->assertSame(1, WithdrawalRule::where('is_active', true)->count());
        $this->assertSame(10000, WithdrawalRule::currentMinCents());
    }
}

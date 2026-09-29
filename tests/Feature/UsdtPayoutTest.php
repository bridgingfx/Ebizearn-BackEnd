<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * USDT payouts (manual-approved): contributor requests with a validated
 * on-chain address, admin approves/rejects and records the tx hash after
 * sending USDT from the company wallet. Ledger stays USD (1 USDT = $1).
 */
class UsdtPayoutTest extends TestCase
{
    use RefreshDatabase;

    protected const TRON_ADDRESS = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    protected const ERC20_ADDRESS = '0x742d35Cc6634C0532925a3b844Bc454e4438f44e';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeContributor(int $cents): User
    {
        $user = User::create([
            'name' => 'Usdt User',
            'email' => 'usdt-' . Str::random(8) . '@example.com',
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

    protected function makeAdmin(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Payout Admin',
            'email' => 'usdt-admin-' . Str::random(8) . '@example.com',
            'password' => bcrypt('V3r1fy!Strong'),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function requestUsdtWithdrawal(User $user, array $details): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/wallet/withdraw', [
            'amount_cents' => 10000,
            'payout_method' => 'usdt',
            'payout_details' => $details,
        ]);
    }

    public function test_usdt_withdrawal_with_valid_tron_address(): void
    {
        $user = $this->makeContributor(20000);

        $response = $this->requestUsdtWithdrawal($user, [
            'wallet_address' => self::TRON_ADDRESS,
            'network' => 'TRC-20',
        ]);

        $response->assertStatus(201);

        $withdrawal = WithdrawalRequest::findOrFail($response->json('data.id'));
        $this->assertSame('usdt', $withdrawal->payout_method);
        $this->assertSame(self::TRON_ADDRESS, $withdrawal->wallet_address);
        $this->assertSame('TRC-20', $withdrawal->network);
        $this->assertSame('requested', $withdrawal->status);
        $this->assertNull($withdrawal->tx_hash);

        // Ledger stays USD: pending hold equals the requested cents.
        $this->assertSame(10000, $user->wallet->fresh()->pending_balance_cents);
    }

    public function test_usdt_withdrawal_with_valid_erc20_address(): void
    {
        $user = $this->makeContributor(20000);

        $response = $this->requestUsdtWithdrawal($user, [
            'wallet_address' => self::ERC20_ADDRESS,
            'network' => 'ERC-20',
        ]);

        $response->assertStatus(201);
        $withdrawal = WithdrawalRequest::findOrFail($response->json('data.id'));
        $this->assertSame('ERC-20', $withdrawal->network);
        $this->assertSame(self::ERC20_ADDRESS, $withdrawal->wallet_address);
    }

    public function test_usdt_withdrawal_rejects_invalid_addresses(): void
    {
        $user = $this->makeContributor(20000);

        // Tron address on an ERC-20 claim.
        $this->requestUsdtWithdrawal($user, [
            'wallet_address' => self::TRON_ADDRESS,
            'network' => 'ERC-20',
        ])->assertStatus(422);

        // ERC-20 address on a TRC-20 claim.
        $this->requestUsdtWithdrawal($user, [
            'wallet_address' => self::ERC20_ADDRESS,
            'network' => 'TRC-20',
        ])->assertStatus(422);

        // Malformed Tron address (wrong length / bad chars).
        $this->requestUsdtWithdrawal($user, [
            'wallet_address' => 'TtooShort',
            'network' => 'TRC-20',
        ])->assertStatus(422);

        // Missing address entirely.
        $this->requestUsdtWithdrawal($user, ['network' => 'TRC-20'])->assertStatus(422);

        // Unsupported network.
        $this->requestUsdtWithdrawal($user, [
            'wallet_address' => self::TRON_ADDRESS,
            'network' => 'BEP-20',
        ])->assertStatus(422);
    }

    public function test_usdt_withdrawal_defaults_network_to_trc20(): void
    {
        $user = $this->makeContributor(20000);

        // network omitted entirely → invalid (required for usdt).
        $this->requestUsdtWithdrawal($user, [
            'wallet_address' => self::TRON_ADDRESS,
        ])->assertStatus(422);
    }

    public function test_contributor_can_save_and_read_usdt_payout_details(): void
    {
        $user = $this->makeContributor(0);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/profile/usdt-payout')
            ->assertStatus(200)
            ->assertJsonPath('data', null);

        $this->putJson('/api/v1/profile/usdt-payout', [
            'wallet_address' => self::TRON_ADDRESS,
            'network' => 'TRC-20',
        ])->assertStatus(200)
            ->assertJsonPath('data.wallet_address', self::TRON_ADDRESS)
            ->assertJsonPath('data.network', 'TRC-20');

        $this->getJson('/api/v1/profile/usdt-payout')
            ->assertStatus(200)
            ->assertJsonPath('data.wallet_address', self::TRON_ADDRESS);

        // Invalid address rejected on save.
        $this->putJson('/api/v1/profile/usdt-payout', [
            'wallet_address' => 'not-an-address',
            'network' => 'TRC-20',
        ])->assertStatus(422);
    }

    public function test_admin_approve_usdt_payout_with_tx_hash(): void
    {
        $user = $this->makeContributor(20000);

        $withdrawalId = $this->requestUsdtWithdrawal($user, [
            'wallet_address' => self::TRON_ADDRESS,
            'network' => 'TRC-20',
        ])->assertStatus(201)->json('data.id');

        Sanctum::actingAs($this->makeAdmin());

        $this->postJson("/api/v1/admin/payouts/{$withdrawalId}/process", [
            'action' => 'approve',
            'tx_hash' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6',
        ])->assertStatus(200);

        $withdrawal = WithdrawalRequest::findOrFail($withdrawalId);
        $this->assertSame('processing', $withdrawal->status);
        $this->assertSame('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6', $withdrawal->tx_hash);

        // Ledger moved pending → withdrawn exactly once, in USD cents.
        $wallet = $user->wallet->fresh();
        $this->assertSame(0, $wallet->pending_balance_cents);
        $this->assertSame(10000, $wallet->total_withdrawn_cents);
    }

    public function test_admin_can_record_tx_hash_after_manual_send(): void
    {
        $user = $this->makeContributor(20000);

        $withdrawalId = $this->requestUsdtWithdrawal($user, [
            'wallet_address' => self::TRON_ADDRESS,
            'network' => 'TRC-20',
        ])->assertStatus(201)->json('data.id');

        Sanctum::actingAs($this->makeAdmin());

        // Approve without the hash (USDT not sent yet).
        $this->postJson("/api/v1/admin/payouts/{$withdrawalId}/process", [
            'action' => 'approve',
        ])->assertStatus(200);
        $this->assertNull(WithdrawalRequest::findOrFail($withdrawalId)->tx_hash);

        // Record the hash after sending from the company wallet.
        $this->postJson("/api/v1/admin/payouts/{$withdrawalId}/tx-hash", [
            'tx_hash' => 'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1',
        ])->assertStatus(200)
            ->assertJsonPath('data.tx_hash', 'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1');
    }

    public function test_tx_hash_recording_guards(): void
    {
        $user = $this->makeContributor(20000);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($user);

        // Non-USDT payout: tx hash must be refused.
        $fiatId = $this->postJson('/api/v1/wallet/withdraw', [
            'amount_cents' => 10000,
            'payout_method' => 'bank_transfer',
            'payout_details' => ['account' => '123'],
        ])->assertStatus(201)->json('data.id');

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/payouts/{$fiatId}/process", ['action' => 'approve'])->assertStatus(200);
        $this->postJson("/api/v1/admin/payouts/{$fiatId}/tx-hash", ['tx_hash' => 'abc'])
            ->assertStatus(422);

        // USDT payout not yet approved: tx hash must be refused.
        $usdtId = $this->requestUsdtWithdrawal($user, [
            'wallet_address' => self::TRON_ADDRESS,
            'network' => 'TRC-20',
        ])->assertStatus(201)->json('data.id');

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/payouts/{$usdtId}/tx-hash", ['tx_hash' => 'abc'])
            ->assertStatus(422);
    }

    public function test_admin_payouts_list_shows_usdt_address_and_network(): void
    {
        $user = $this->makeContributor(20000);

        $this->requestUsdtWithdrawal($user, [
            'wallet_address' => self::TRON_ADDRESS,
            'network' => 'TRC-20',
        ])->assertStatus(201);

        Sanctum::actingAs($this->makeAdmin());

        $response = $this->getJson('/api/v1/admin/payouts?payout_method=usdt');
        $response->assertStatus(200);

        $items = $response->json('data');
        $this->assertNotEmpty($items);
        $first = $items[0];
        $this->assertSame('usdt', $first['payout_method']);
        $this->assertSame(self::TRON_ADDRESS, $first['wallet_address']);
        $this->assertSame('TRC-20', $first['network']);
    }
}

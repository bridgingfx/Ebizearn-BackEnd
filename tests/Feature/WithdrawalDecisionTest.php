<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A withdrawal is decided once: approve → processing (funds leave pending),
 * reject → refunded to available. A second decision is refused, and the
 * contributor's history shows the request's current status.
 */
class WithdrawalDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeUser(string $role, int $cents = 0): User
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
        Wallet::firstOrCreate(['user_id' => $user->id], ['currency' => 'USD', 'available_balance_cents' => $cents]);

        return $user;
    }

    protected function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    protected function requestWithdrawal(User $user, int $cents): int
    {
        $this->actAs($user);

        return $this->postJson('/api/v1/wallet/withdraw', [
            'amount_cents' => $cents,
            'payout_method' => 'bank_transfer',
            'payout_details' => ['account' => '123'],
        ])->assertStatus(201)->json('data.id');
    }

    protected function historyStatus(User $user): ?string
    {
        $this->actAs($user);
        $rows = $this->getJson('/api/v1/wallet/transactions')->assertOk()->json('data');

        return collect($rows)->firstWhere('type', 'withdrawal')['withdrawal_status'] ?? null;
    }

    public function test_approve_moves_the_request_on_and_cannot_be_decided_again(): void
    {
        $user = $this->makeUser('contributor', 10000);
        $id = $this->requestWithdrawal($user, 6000);
        $this->assertSame('requested', $this->historyStatus($user));

        $this->actAs($this->makeUser('admin'));
        $this->postJson("/api/v1/admin/payouts/{$id}/process", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'processing');

        // Second click (approve or reject) is refused and changes nothing.
        $this->postJson("/api/v1/admin/payouts/{$id}/process", ['action' => 'approve'])
            ->assertStatus(409)->assertJsonPath('data.status', 'processing');
        $this->postJson("/api/v1/admin/payouts/{$id}/process", ['action' => 'reject', 'reason' => 'oops'])
            ->assertStatus(409)->assertJsonPath('message', 'This withdrawal was already approved.');

        $wallet = $user->wallet->fresh();
        $this->assertSame(4000, $wallet->available_balance_cents);
        $this->assertSame(0, $wallet->pending_balance_cents);
        $this->assertSame(6000, $wallet->total_withdrawn_cents);
        $this->assertSame('processing', $this->historyStatus($user));
    }

    public function test_reject_returns_the_money_to_the_wallet_once(): void
    {
        $user = $this->makeUser('contributor', 10000);
        $id = $this->requestWithdrawal($user, 6000);

        $this->actAs($this->makeUser('admin'));
        $this->postJson("/api/v1/admin/payouts/{$id}/process", ['action' => 'reject'])->assertStatus(422); // reason required
        $this->postJson("/api/v1/admin/payouts/{$id}/process", ['action' => 'reject', 'reason' => 'Bank details wrong'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->postJson("/api/v1/admin/payouts/{$id}/process", ['action' => 'reject', 'reason' => 'again'])->assertStatus(409);
        $this->postJson("/api/v1/admin/payouts/{$id}/process", ['action' => 'approve'])->assertStatus(409);

        $wallet = $user->wallet->fresh();
        $this->assertSame(10000, $wallet->available_balance_cents);
        $this->assertSame(0, $wallet->pending_balance_cents);
        $this->assertSame(0, $wallet->total_withdrawn_cents);
        $this->assertSame(1, WalletTransaction::where('type', 'withdrawal_reversal')->count());
        $this->assertSame('rejected', WithdrawalRequest::find($id)->status);
        $this->assertSame('rejected', $this->historyStatus($user));
    }
}

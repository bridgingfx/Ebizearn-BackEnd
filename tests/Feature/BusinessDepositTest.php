<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\DepositMethod;
use App\Models\DepositRequest;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Business wallet deposits: Super Admin turns methods on with their details,
 * businesses see only active methods and submit deposits, staff approve and
 * the wallet is credited exactly once.
 */
class BusinessDepositTest extends TestCase
{
    use RefreshDatabase;

    protected function user(string $role): User
    {
        $user = User::factory()->create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' Person',
            'email' => $role . Str::random(5) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        if ($role === 'business') {
            Business::create(['owner_id' => $user->id, 'company_name' => 'Acme', 'status' => 'active']);
        }

        return $user;
    }

    protected function enable(string $key, array $details, array $extra = []): void
    {
        Sanctum::actingAs($this->user('superadmin'));
        $this->putJson("/api/v1/admin/deposit-methods/{$key}", array_merge([
            'is_active' => true,
            'title' => DepositMethod::where('key', $key)->value('title'),
            'instructions' => 'Pay here.',
            'details' => $details,
            'min_amount' => 10,
        ], $extra))->assertOk();
    }

    public function test_super_admin_configures_methods_and_business_sees_only_active_ones(): void
    {
        Sanctum::actingAs($this->user('superadmin'));

        // Details are required before a method can go live.
        $this->putJson('/api/v1/admin/deposit-methods/card', [
            'is_active' => true, 'title' => 'Card payment', 'details' => ['provider' => 'AcmePay'], 'min_amount' => 10,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Fill in a valid payment link (https://…) before turning Card payment on.']);

        $this->enable('card', ['payment_link' => 'https://pay.example.com/x', 'provider' => 'AcmePay', 'junk' => 'dropped']);
        $this->enable('bank', ['account_name' => 'eBiz Network FZ LLC', 'iban' => 'AE070331234567890123456', 'bank_name' => 'Emirates NBD']);
        $this->assertArrayNotHasKey('junk', DepositMethod::where('key', 'card')->first()->details);

        Sanctum::actingAs($this->user('business'));
        $methods = $this->getJson('/api/v1/business/deposit-methods')->assertOk()->json('data');
        $this->assertSame(['bank', 'card'], collect($methods)->pluck('key')->sort()->values()->all());
        $this->assertSame('https://pay.example.com/x', collect($methods)->firstWhere('key', 'card')['details']['payment_link']);

        // Admins cannot change deposit methods.
        Sanctum::actingAs($this->user('admin'));
        $this->putJson('/api/v1/admin/deposit-methods/card', ['is_active' => false, 'title' => 'x', 'min_amount' => 1])->assertForbidden();
    }

    public function test_crypto_deposit_method_is_disabled_in_mvp(): void
    {
        Sanctum::actingAs($this->user('superadmin'));

        // Super Admin cannot turn crypto on — even through the route's key list.
        $this->putJson('/api/v1/admin/deposit-methods/crypto', [
            'is_active' => true,
            'title' => 'Crypto (USDT)',
            'instructions' => 'Pay here.',
            'details' => ['currency' => 'USDT', 'network' => 'TRC20', 'wallet_address' => 'TXabc123'],
            'min_amount' => 10,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Crypto deposits are disabled in the MVP.']);

        // A crypto gateway cannot be registered either.
        $this->postJson('/api/v1/admin/payments/gateways', [
            'name' => 'CryptoX',
            'driver' => 'crypto',
            'credentials' => ['api_key' => 'x'],
        ])->assertStatus(422);

        // A legacy crypto row forced active in the DB is still hidden from
        // businesses and rejected on submit.
        DepositMethod::where('key', 'crypto')->update(['is_active' => true]);

        Sanctum::actingAs($this->user('business'));
        $methods = $this->getJson('/api/v1/business/deposit-methods')->assertOk()->json('data');
        $this->assertNotContains('crypto', collect($methods)->pluck('key')->all());

        $this->post('/api/v1/business/deposits', ['method' => 'crypto', 'amount' => 100, 'reference' => '0xhash1'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('method');
    }

    public function test_deposit_is_credited_only_after_approval_and_only_once(): void
    {
        Storage::fake('local');
        $this->enable('card', ['payment_link' => 'https://pay.example.com/x', 'provider' => 'AcmePay']);
        $business = $this->user('business');
        Sanctum::actingAs($business);

        // Inactive method, missing reference, too small.
        $this->post('/api/v1/business/deposits', ['method' => 'bank', 'amount' => 50], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/v1/business/deposits', ['method' => 'card', 'amount' => 50], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->post('/api/v1/business/deposits', ['method' => 'card', 'amount' => 5, 'reference' => 'PAY-1'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $id = $this->post('/api/v1/business/deposits', [
            'method' => 'card', 'amount' => '250.50', 'reference' => 'PAY-1',
            'proof' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.amount_cents', 25050)->json('data.id');

        // Same reference cannot be claimed twice.
        $this->post('/api/v1/business/deposits', ['method' => 'card', 'amount' => 100, 'reference' => 'PAY-1'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('reference');

        $wallet = Wallet::where('user_id', $business->id)->first();
        $this->assertSame(0, $wallet->available_balance_cents, 'Nothing is credited before approval.');
        $this->getJson('/api/v1/business/deposits')->assertOk()->assertJsonPath('data.deposits.0.status', 'pending');

        // Business cannot review; staff with process_payouts can.
        $this->postJson("/api/v1/admin/deposits/{$id}/decision", ['decision' => 'approve'])->assertForbidden();

        $admin = $this->user('admin');
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/deposits')->assertOk()->assertJsonPath('meta.pending', 1)->assertJsonPath('data.0.user.business.company_name', 'Acme');
        $this->get("/api/v1/admin/deposits/{$id}/proof")->assertOk();

        // Approve the amount that actually arrived (network fee deducted).
        $this->postJson("/api/v1/admin/deposits/{$id}/decision", ['decision' => 'approve', 'amount' => 249])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonFragment(['message' => 'USD 249.00 was added to the business wallet.']);
        $this->postJson("/api/v1/admin/deposits/{$id}/decision", ['decision' => 'approve'])->assertStatus(422);

        $this->assertSame(24900, $wallet->fresh()->available_balance_cents);
        $this->assertDatabaseHas('wallet_transactions', ['wallet_id' => $wallet->id, 'type' => 'deposit', 'amount_cents' => 24900, 'reference_type' => 'deposit_request']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deposit.approved', 'actor_id' => $admin->id]);

        Sanctum::actingAs($business);
        $this->getJson('/api/v1/business/deposits')->assertOk()
            ->assertJsonPath('data.wallet.available_balance_cents', 24900)
            ->assertJsonPath('data.transactions.0.type', 'deposit');
    }

    public function test_rejection_needs_a_reason_and_credits_nothing(): void
    {
        $this->enable('email', ['contact_email' => 'finance@ebizearn.com']);
        $business = $this->user('business');
        Sanctum::actingAs($business);
        $id = $this->postJson('/api/v1/business/deposits', ['method' => 'email', 'amount' => 500, 'note' => 'Invoice please'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->user('superadmin'));
        $this->postJson("/api/v1/admin/deposits/{$id}/decision", ['decision' => 'reject'])->assertStatus(422);
        $this->postJson("/api/v1/admin/deposits/{$id}/decision", ['decision' => 'reject', 'note' => 'Payment not received'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(0, (int) Wallet::where('user_id', $business->id)->value('available_balance_cents'));
        $this->assertSame('Payment not received', DepositRequest::find($id)->review_note);
    }
}

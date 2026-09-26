<?php

namespace Tests\Feature;

use App\Models\FraudEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\SocialTokenVerifier;
use App\Services\Auth\SocialTokenVerificationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Round 2 — social login (Google / Apple).
 *
 * The real JWT verifier is swapped for a fake: network + cryptography are
 * not what is under test here — the find-or-create/link/token/role logic
 * and the fail-closed behaviour are.
 */
class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    protected function fakeVerifier(array $claims = [], bool $throw = false): FakeSocialTokenVerifier
    {
        $fake = new FakeSocialTokenVerifier($claims, $throw);
        $this->app->singleton(SocialTokenVerifier::class, fn () => $fake);

        return $fake;
    }

    public function test_valid_google_token_creates_contributor_and_returns_token(): void
    {
        $this->fakeVerifier([
            'sub' => 'google-sub-123',
            'email' => 'social@example.com',
            'email_verified' => true,
            'name' => 'Social User',
        ]);

        $response = $this->postJson('/api/v1/auth/social/google', ['id_token' => 'fake-token', 'terms_version' => '1.0']);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'contributor')
            ->assertJsonPath('data.user.email', 'social@example.com')
            ->assertJsonPath('data.user.email_verified', true);
        $this->assertNotEmpty($response->json('data.token'));

        $user = User::where('email', 'social@example.com')->firstOrFail();
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_sub' => 'google-sub-123',
        ]);
        // New social registrations are logged in fraud telemetry.
        $this->assertDatabaseHas('fraud_events', [
            'user_id' => $user->id,
            'event_type' => 'registration',
            'status' => 'reviewed',
        ]);
        $this->assertNotNull($user->wallet);
        $this->assertNotNull($user->profile);
    }

    public function test_google_signup_from_business_pages_creates_a_business_account(): void
    {
        $this->fakeVerifier(['sub' => 'g-biz', 'email' => 'owner@acme.com', 'email_verified' => true, 'name' => 'Acme Owner']);

        $this->postJson('/api/v1/auth/social/google', ['id_token' => 't', 'portal' => 'business', 'terms_version' => '1.0'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'business')
            ->assertJsonPath('data.user.business.company_name', 'Acme Owner Co');

        // Signing in again from the business login works; from the contributor login it is refused.
        $this->postJson('/api/v1/auth/social/google', ['id_token' => 't', 'portal' => 'business', 'terms_version' => '1.0'])->assertOk();
        $this->postJson('/api/v1/auth/social/google', ['id_token' => 't', 'portal' => 'contributor'])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'This Google account is registered as a business account. Please use the business sign-in.']);
        $this->assertSame(1, User::where('email', 'owner@acme.com')->count());
    }

    public function test_staff_portals_never_accept_social_sign_in(): void
    {
        // Not even for an existing staff member with a matching verified email.
        User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Mod', 'email' => 'mod@example.com', 'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'moderator', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $this->fakeVerifier(['sub' => 'g-mod', 'email' => 'mod@example.com', 'email_verified' => true]);

        foreach (['moderator', 'superadmin'] as $portal) {
            $this->postJson('/api/v1/auth/social/google', ['id_token' => 't', 'portal' => $portal])
                ->assertStatus(403)
                ->assertJsonFragment(['message' => 'Staff sign in with their work email and password only.']);
        }
        $this->assertDatabaseMissing('social_accounts', ['provider_sub' => 'g-mod']);
    }

    public function test_unconfigured_google_client_id_gives_a_clear_503(): void
    {
        $this->app->singleton(SocialTokenVerifier::class, fn () => new class implements SocialTokenVerifier {
            public function verifyGoogle(string $idToken): array
            {
                throw new SocialTokenVerificationException('Google client ID is not configured.');
            }

            public function verifyApple(string $idToken): array
            {
                throw new SocialTokenVerificationException('Apple client ID is not configured.');
            }
        });

        $this->postJson('/api/v1/auth/social/google', ['id_token' => 't'])
            ->assertStatus(503)
            ->assertJsonFragment(['message' => 'Google sign-in is not available right now. Please use your email and password.']);
    }

    public function test_invalid_token_is_rejected_with_401(): void
    {
        $this->fakeVerifier(throw: true);

        $this->postJson('/api/v1/auth/social/google', ['id_token' => 'bogus'])
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('users', ['email' => 'social@example.com']);
    }

    public function test_unsupported_provider_is_rejected(): void
    {
        // Route constraint: only google|apple resolve; anything else 404s.
        $this->postJson('/api/v1/auth/social/facebook', ['id_token' => 'x'])
            ->assertStatus(404);
    }

    public function test_verified_email_links_to_existing_account(): void
    {
        $existing = User::create([
            'name' => 'Existing', 'email' => 'linked@example.com',
            'password' => Hash::make('V3r1fy!Strong'), 'role' => 'contributor',
            'status' => 'active', 'email_verified_at' => now(),
        ]);

        $this->fakeVerifier([
            'sub' => 'google-sub-999',
            'email' => 'linked@example.com',
            'email_verified' => true,
            'name' => 'Existing',
        ]);

        $this->postJson('/api/v1/auth/social/google', ['id_token' => 'fake-token', 'terms_version' => '1.0'])
            ->assertStatus(200)
            ->assertJsonPath('data.user.id', $existing->id);

        // No duplicate account was created; the identity was linked.
        $this->assertSame(1, User::where('email', 'linked@example.com')->count());
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $existing->id,
            'provider' => 'google',
            'provider_sub' => 'google-sub-999',
        ]);
    }

    public function test_unverified_email_claim_does_not_link_to_existing_account(): void
    {
        User::create([
            'name' => 'Victim', 'email' => 'victim@example.com',
            'password' => Hash::make('V3r1fy!Strong'), 'role' => 'contributor',
            'status' => 'active', 'email_verified_at' => now(),
        ]);

        // email_verified=false: the claim is not trustworthy, so we must NOT
        // link to victim@example.com — a fresh account is created instead.
        $this->fakeVerifier([
            'sub' => 'attacker-sub',
            'email' => 'victim@example.com',
            'email_verified' => false,
            'name' => 'Attacker',
        ]);

        $before = User::count();
        $response = $this->postJson('/api/v1/auth/social/google', ['id_token' => 'fake-token', 'terms_version' => '1.0']);
        $response->assertStatus(200);

        $this->assertSame($before + 1, User::count());
        $this->assertDatabaseMissing('social_accounts', [
            'provider_sub' => 'attacker-sub',
            'user_id' => User::where('email', 'victim@example.com')->firstOrFail()->id,
        ]);
    }

    public function test_apple_token_with_hidden_email_uses_client_email(): void
    {
        $this->fakeVerifier([
            'sub' => 'apple-sub-hidden',
            'email' => null, // Apple hides email after first authorization
            'email_verified' => null,
            'name' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/social/apple', [
            'id_token' => 'fake-token',
            'email' => 'appleuser@example.com',
            'terms_version' => '1.0',
            'name' => 'Apple User',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.email', 'appleuser@example.com')
            ->assertJsonPath('data.user.email_verified', false);

        $user = User::where('email', 'appleuser@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->email_verification_token); // can verify later
    }

    public function test_apple_token_with_no_email_at_all_mints_placeholder(): void
    {
        $this->fakeVerifier([
            'sub' => 'apple-sub-noemail',
            'email' => null,
            'email_verified' => null,
            'name' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/social/apple', ['id_token' => 'fake-token', 'terms_version' => '1.0']);

        $response->assertStatus(200);
        $email = $response->json('data.user.email');
        $this->assertStringEndsWith('@users.ebizearn.internal', $email);
    }

    public function test_repeat_social_login_signs_in_without_duplicating(): void
    {
        $this->fakeVerifier([
            'sub' => 'google-sub-repeat',
            'email' => 'repeat@example.com',
            'email_verified' => true,
            'name' => 'Repeat',
        ]);

        $this->postJson('/api/v1/auth/social/google', ['id_token' => 'fake-token', 'terms_version' => '1.0'])->assertStatus(200);
        $this->postJson('/api/v1/auth/social/google', ['id_token' => 'fake-token', 'terms_version' => '1.0'])->assertStatus(200);

        $this->assertSame(1, User::where('email', 'repeat@example.com')->count());
        $this->assertSame(1, SocialAccount::where('provider_sub', 'google-sub-repeat')->count());
    }

    public function test_suspended_account_cannot_social_login(): void
    {
        $this->fakeVerifier([
            'sub' => 'google-sub-susp',
            'email' => 'suspended@example.com',
            'email_verified' => true,
            'name' => 'Suspended',
        ]);

        $this->postJson('/api/v1/auth/social/google', ['id_token' => 'fake-token', 'terms_version' => '1.0'])->assertStatus(200);
        User::where('email', 'suspended@example.com')->firstOrFail()
            ->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/v1/auth/social/google', ['id_token' => 'fake-token', 'terms_version' => '1.0'])
            ->assertStatus(403);
    }
}

/**
 * Test double for the ID-token verifier: no network, no crypto.
 */
class FakeSocialTokenVerifier implements SocialTokenVerifier
{
    public function __construct(
        private array $claims = [],
        private bool $throw = false,
    ) {
    }

    public function verifyGoogle(string $idToken): array
    {
        return $this->verify();
    }

    public function verifyApple(string $idToken): array
    {
        return $this->verify();
    }

    private function verify(): array
    {
        if ($this->throw) {
            throw new SocialTokenVerificationException('Fake rejection.');
        }

        return array_merge([
            'sub' => 'fake-sub',
            'email' => null,
            'email_verified' => null,
            'name' => null,
        ], $this->claims);
    }
}

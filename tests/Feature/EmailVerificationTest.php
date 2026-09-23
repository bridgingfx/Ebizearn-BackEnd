<?php

namespace Tests\Feature;

use App\Mail\VerifyEmail;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Round 2 — email verification flow.
 *
 * Registration issues a token (digest-only storage) and sends the branded
 * email; the verify endpoint marks the address; money/task write paths are
 * gated with a machine-readable 403 until verification.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    protected function registerUnverified(string $email): User
    {
        // The legacy token-link flow serves PRE-EXISTING unverified accounts
        // (new signups go through OTP), so build one directly: active
        // status, unverified email.
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Verify Me',
            'email' => $email,
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => null,
        ]);
    }

    protected function issueToken(User $user): string
    {
        $url = app(EmailVerificationService::class)->issue($user);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query['token'];
    }

    protected function authHeaders(User $user): array
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'V3r1fy!Strong',
        ])->assertStatus(200);

        return ['Authorization' => 'Bearer ' . $login->json('data.token')];
    }

    public function test_verification_email_issue_stores_digest_and_sends_branded_email(): void
    {
        $user = $this->registerUnverified('verify1@example.com');

        $url = app(EmailVerificationService::class)->issue($user);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['token'] ?? null);

        $user = $user->fresh();
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->email_verified);
        $this->assertNotNull($user->email_verification_token);
        // Digest-only storage: the raw token is not the stored value.
        $this->assertSame(64, strlen($user->email_verification_token));
        $this->assertSame(hash('sha256', $query['token']), $user->email_verification_token);

        Mail::assertSent(VerifyEmail::class, function (VerifyEmail $mail) {
            return $mail->hasTo('verify1@example.com')
                && str_contains($mail->verifyUrl, '/verify-email?token=');
        });
    }

    public function test_verify_marks_email_verified(): void
    {
        $user = $this->registerUnverified('verify2@example.com');
        $token = $this->issueToken($user);

        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])
            ->assertStatus(200)
            ->assertJsonPath('data.user.email_verified', true);

        $user = $user->fresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_token); // single use
    }

    public function test_verify_with_invalid_token_fails(): void
    {
        $this->registerUnverified('verify3@example.com');

        $this->postJson('/api/v1/auth/email/verify', ['token' => 'not-a-real-token'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNull(User::where('email', 'verify3@example.com')->firstOrFail()->email_verified_at);
    }

    public function test_verify_with_expired_token_fails(): void
    {
        $user = $this->registerUnverified('verify4@example.com');
        $token = $this->issueToken($user);

        $user->forceFill(['email_verification_sent_at' => now()->subHours(25)])->save();

        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_token_cannot_be_reused(): void
    {
        $user = $this->registerUnverified('verify5@example.com');
        $token = $this->issueToken($user);

        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertStatus(200);
        // Second use of the same token fails (it was cleared).
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertStatus(422);
    }

    public function test_unverified_user_is_blocked_from_money_write_paths(): void
    {
        $user = $this->registerUnverified('verify6@example.com');
        $headers = $this->authHeaders($user);

        // /me still works and reports the unverified state.
        $this->withHeaders($headers)->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.user.email_verified', false);

        // Money/task write paths answer 403 with the machine-readable code.
        $this->withHeaders($headers)->getJson('/api/v1/wallet')
            ->assertStatus(403)
            ->assertJson(['code' => 'email_not_verified', 'success' => false]);

        $this->withHeaders($headers)->getJson('/api/v1/contributor/dashboard')
            ->assertStatus(403)
            ->assertJson(['code' => 'email_not_verified']);
    }

    public function test_verified_user_passes_the_gate(): void
    {
        $user = $this->registerUnverified('verify7@example.com');
        $this->postJson('/api/v1/auth/email/verify', ['token' => $this->issueToken($user)])
            ->assertStatus(200);

        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.user.email_verified', true);

        // Wallet read now passes the gate (empty wallet, but 200 not 403).
        $this->withHeaders($headers)->getJson('/api/v1/wallet')->assertStatus(200);
    }

    public function test_resend_regenerates_token_and_sends_email(): void
    {
        $user = $this->registerUnverified('verify8@example.com');
        $oldDigest = $user->email_verification_token;
        $headers = $this->authHeaders($user);

        Mail::fake(); // clear the registration mail
        $this->withHeaders($headers)->postJson('/api/v1/auth/email/resend')
            ->assertStatus(200);

        $user = $user->fresh();
        $this->assertNotSame($oldDigest, $user->email_verification_token);
        Mail::assertSent(VerifyEmail::class, fn (VerifyEmail $m) => $m->hasTo('verify8@example.com'));
    }

    public function test_resend_rejects_already_verified_users(): void
    {
        $user = $this->registerUnverified('verify9@example.com');
        $this->postJson('/api/v1/auth/email/verify', ['token' => $this->issueToken($user)])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/v1/auth/email/resend')
            ->assertStatus(422);
    }

    public function test_resend_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/email/resend')->assertStatus(401);
    }

    public function test_resend_is_throttled(): void
    {
        $user = $this->registerUnverified('verify10@example.com');
        $headers = $this->authHeaders($user);

        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders($headers)->postJson('/api/v1/auth/email/resend')->assertStatus(200);
        }
        // 4th request within the minute is rate-limited.
        $this->withHeaders($headers)->postJson('/api/v1/auth/email/resend')
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited']);
    }

    public function test_forgot_password_still_works_for_unverified_users(): void
    {
        $this->registerUnverified('verify11@example.com');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'verify11@example.com'])
            ->assertStatus(200);
    }
}

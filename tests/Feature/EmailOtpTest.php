<?php

namespace Tests\Feature;

use App\Mail\EmailOtpMail;
use App\Models\EmailOtp;
use App\Models\User;
use App\Exceptions\EmailOtpException;
use App\Services\Auth\EmailOtpService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Signup hardening — phone collection + email OTP verification.
 *
 * Covers: phone validation on signup, pending-verification account state
 * (no token at signup, login gated), OTP send (happy path, validation,
 * cooldown, rate limits) and OTP verify (happy, wrong code, expired,
 * too many attempts, resend invalidation).
 */
class EmailOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Otp Tester',
            'email' => 'otp@example.com',
            'password' => 'V3r1fy!Strong',
            'role' => 'contributor',
            'phone_country_code' => '+971',
            'phone_number' => '501234567',
            'terms_version' => '1.0',
        ], $overrides);
    }

    protected function mailedCode(string $email): ?string
    {
        $code = null;
        Mail::assertSent(EmailOtpMail::class, function (EmailOtpMail $mail) use ($email, &$code) {
            if ($mail->hasTo($email)) {
                $code = $mail->code; // last sent wins (resend case)

                return true;
            }

            return false;
        });

        return $code;
    }

    // ------------------------------------------------------------------
    // Signup: phone validation
    // ------------------------------------------------------------------

    public function test_signup_requires_phone_fields(): void
    {
        $payload = $this->registerPayload();
        unset($payload['phone_country_code'], $payload['phone_number']);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_country_code', 'phone_number']);
    }

    public function test_signup_rejects_unknown_country_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'phone_country_code' => '+999',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_country_code']);

        $this->assertNull(User::where('email', 'otp@example.com')->first());
    }

    public function test_signup_rejects_bad_phone_number(): void
    {
        // Non-digit characters.
        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'email' => 'bad1@example.com',
            'phone_number' => '50-1234ab',
        ]))->assertStatus(422)->assertJsonValidationErrors(['phone_number']);

        // Too short (min 4 digits).
        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'email' => 'bad2@example.com',
            'phone_number' => '123',
        ]))->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
    }

    // ------------------------------------------------------------------
    // Signup: pending-verification state
    // ------------------------------------------------------------------

    public function test_signup_creates_pending_account_and_sends_otp(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonPath('data.otp.expires_in_seconds', 600);

        // NO token is issued at signup — the user is not logged in yet.
        $this->assertArrayNotHasKey('token', $response->json('data'));

        $user = User::where('email', 'otp@example.com')->firstOrFail();
        $this->assertSame('pending_verification', $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('+971501234567', $user->phone);

        // Branded OTP email went out with a 6-digit code.
        $code = $this->mailedCode('otp@example.com');
        $this->assertNotNull($code);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        // Code is stored HASHED, never plaintext; 10-minute expiry.
        $otp = EmailOtp::where('email', 'otp@example.com')->firstOrFail();
        $this->assertNotSame($code, $otp->code_hash);
        $this->assertTrue(Hash::check($code, $otp->code_hash));
        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp, $otp->expires_at->timestamp, 5);
    }

    public function test_signup_normalizes_country_code_without_plus(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'phone_country_code' => '971',
        ]))->assertStatus(201);

        $this->assertSame('+971501234567', User::where('email', 'otp@example.com')->firstOrFail()->phone);
    }

    public function test_business_signup_also_requires_phone_and_otp(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'email' => 'biz@example.com',
            'role' => 'business',
            'company_name' => 'Acme LLC',
            'phone_country_code' => '+1',
            'phone_number' => '5551234567',
        ]));

        $response->assertStatus(201)->assertJsonPath('data.requires_otp', true);

        $user = User::where('email', 'biz@example.com')->firstOrFail();
        $this->assertSame('business', $user->role);
        $this->assertSame('pending_verification', $user->status);
        $this->assertSame('+15551234567', $user->phone);
        $this->assertNotNull($user->business);
    }

    public function test_login_is_blocked_until_otp_verified(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'otp@example.com',
            'password' => 'V3r1fy!Strong',
        ])->assertStatus(403)->assertJson(['code' => 'email_unverified', 'success' => false]);
    }

    // ------------------------------------------------------------------
    // OTP send
    // ------------------------------------------------------------------

    public function test_signup_fails_loudly_when_otp_email_cannot_be_sent(): void
    {
        // Simulate a dead/misconfigured mailer: registration must FAIL
        // loudly (503, machine code) and roll back — never leave a
        // half-created account or pretend the code went out.
        $this->mock(EmailOtpService::class, function ($mock) {
            $mock->shouldReceive('issue')->andThrow(
                new EmailOtpException('email_failed', 'SMTP unavailable', 503)
            );
        });

        $this->postJson('/api/v1/auth/register', $this->registerPayload())
            ->assertStatus(503)
            ->assertJson(['code' => 'email_failed', 'success' => false]);

        $this->assertNull(User::where('email', 'otp@example.com')->first());
        $this->assertSame(0, EmailOtp::count());
    }

    public function test_otp_send_validation(): void
    {
        $this->postJson('/api/v1/auth/otp/send', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/v1/auth/otp/send', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_otp_send_unknown_email_returns_not_found(): void
    {
        $this->postJson('/api/v1/auth/otp/send', ['email' => 'nobody@example.com'])
            ->assertStatus(404)
            ->assertJson(['code' => 'not_found', 'success' => false]);
    }

    public function test_otp_send_cooldown(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        // Registration already sent one code; an immediate resend is blocked.
        $this->postJson('/api/v1/auth/otp/send', ['email' => 'otp@example.com'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'cooldown')
            ->assertJsonStructure(['data' => ['retry_after_seconds']]);

        $this->assertGreaterThan(0, $this->postJson('/api/v1/auth/otp/send', ['email' => 'otp@example.com'])->json('data.retry_after_seconds'));
    }

    public function test_otp_send_rate_limited_after_five_per_hour(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        // Registration = send #1. Four more resends, each past the cooldown.
        for ($i = 0; $i < 4; $i++) {
            Carbon::setTestNow(now()->addSeconds(61));
            $this->postJson('/api/v1/auth/otp/send', ['email' => 'otp@example.com'])->assertStatus(200);
        }

        // 6th send within the hour is rejected.
        Carbon::setTestNow(now()->addSeconds(61));
        $this->postJson('/api/v1/auth/otp/send', ['email' => 'otp@example.com'])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited', 'success' => false]);
    }

    public function test_otp_send_rejects_already_verified_users(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        $code = $this->mailedCode('otp@example.com');
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'code' => $code])
            ->assertStatus(200);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->postJson('/api/v1/auth/otp/send', ['email' => 'otp@example.com'])
            ->assertStatus(409)
            ->assertJson(['code' => 'already_verified', 'success' => false]);
    }

    // ------------------------------------------------------------------
    // OTP verify
    // ------------------------------------------------------------------

    public function test_otp_verify_happy_path_activates_and_logs_in(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);
        $code = $this->mailedCode('otp@example.com');

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'otp@example.com',
            'code' => $code,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        $user = User::where('email', 'otp@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('active', $user->status);
        $this->assertNotNull(EmailOtp::where('email', 'otp@example.com')->first()->used_at);

        // The issued token authenticates. (Checked before the password
        // login below, because password login intentionally revokes prior
        // tokens — single-active-session policy.)
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200);

        // Login now works (the gate is lifted).
        $this->postJson('/api/v1/auth/login', [
            'email' => 'otp@example.com',
            'password' => 'V3r1fy!Strong',
        ])->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_otp_verify_wrong_code_returns_invalid_with_attempts_left(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'code' => '000000'])
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid', 'success' => false])
            ->assertJsonPath('data.attempts_remaining', 4);

        $this->assertNull(User::where('email', 'otp@example.com')->firstOrFail()->email_verified_at);
    }

    public function test_otp_verify_too_many_attempts_invalidates_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);
        $correct = $this->mailedCode('otp@example.com');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'code' => '000000'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'invalid');
        }

        // 5th wrong attempt kills the code.
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'code' => '000000'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts');

        // Even the correct code no longer works — a resend is required.
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'code' => $correct])
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');
    }

    public function test_otp_verify_expired_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);
        $code = $this->mailedCode('otp@example.com');

        Carbon::setTestNow(now()->addMinutes(11));

        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'code' => $code])
            ->assertStatus(410)
            ->assertJsonPath('code', 'expired');

        $this->assertNull(User::where('email', 'otp@example.com')->firstOrFail()->email_verified_at);
    }

    public function test_otp_resend_invalidates_previous_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);
        $firstCode = $this->mailedCode('otp@example.com');

        Carbon::setTestNow(now()->addSeconds(61));
        $this->postJson('/api/v1/auth/otp/send', ['email' => 'otp@example.com'])->assertStatus(200);
        $secondCode = $this->mailedCode('otp@example.com');
        $this->assertNotSame($firstCode, $secondCode);

        // Old code no longer verifies…
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'code' => $firstCode])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid');

        // …but the fresh one does.
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'code' => $secondCode])
            ->assertStatus(200);
    }

    public function test_otp_verify_rejects_already_verified_users(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);
        $code = $this->mailedCode('otp@example.com');

        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'code' => $code])
            ->assertStatus(200);

        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'code' => $code])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_verified');
    }

    public function test_otp_verify_unknown_email_returns_not_found(): void
    {
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'nobody@example.com', 'code' => '123456'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');
    }

    public function test_otp_verify_rejects_malformed_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'otp@example.com', 'code' => '12ab'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }
}

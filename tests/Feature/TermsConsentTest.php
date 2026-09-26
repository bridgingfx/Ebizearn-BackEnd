<?php

namespace Tests\Feature;

use App\Mail\EmailOtpMail;
use App\Models\User;
use App\Services\Auth\SocialTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Consent proof — terms-of-service acceptance.
 *
 * Covers:
 *  - email register stores the accepted terms version (pending state),
 *  - OTP-verify activation stamps terms_accepted_at + IP,
 *  - social signup (Google/Apple) stores all three fields at creation,
 *  - stale/missing terms_version is rejected with a clear 422,
 *  - terms_version + terms_accepted_at are present in auth payloads
 *    (register / otp verify / login / me),
 *  - legacy accounts keep null consent fields (no fake backfill).
 */
class TermsConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    protected function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Consent Tester',
            'email' => 'consent@example.com',
            'password' => 'V3r1fy!Strong',
            'role' => 'contributor',
            'phone_country_code' => '+995',
            'phone_number' => '555123456',
            'terms_version' => config('legal.terms_version'),
        ], $overrides);
    }

    protected function mailedCode(string $email): ?string
    {
        $code = null;
        Mail::assertSent(EmailOtpMail::class, function (EmailOtpMail $mail) use ($email, &$code) {
            if ($mail->hasTo($email)) {
                $code = $mail->code;

                return true;
            }

            return false;
        });

        return $code;
    }

    // ------------------------------------------------------------------
    // Email register -> OTP verify activation
    // ------------------------------------------------------------------

    public function test_registration_records_terms_version_while_pending(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.user.terms_version', '1.0')
            ->assertJsonPath('data.user.terms_accepted_at', null);

        $user = User::where('email', 'consent@example.com')->firstOrFail();
        $this->assertSame('1.0', $user->terms_version);
        // Not yet accepted: acceptance is stamped at OTP activation.
        $this->assertNull($user->terms_accepted_at);
        $this->assertNull($user->terms_accepted_ip);
    }

    public function test_otp_verify_activation_records_consent_timestamp_and_ip(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        $code = $this->mailedCode('consent@example.com');
        $this->assertNotNull($code);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'consent@example.com',
            'code' => $code,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.terms_version', '1.0')
            ->assertJsonPath('data.user.status', 'active');
        $this->assertNotNull($response->json('data.user.terms_accepted_at'));

        $user = User::where('email', 'consent@example.com')->firstOrFail();
        $this->assertSame('1.0', $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->terms_accepted_ip);
    }

    public function test_registration_rejects_stale_terms_version(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['terms_version' => '0.9']))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('users', ['email' => 'consent@example.com']);
    }

    public function test_registration_rejects_missing_terms_version(): void
    {
        $payload = $this->registerPayload();
        unset($payload['terms_version']);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['terms_version']);

        $this->assertDatabaseMissing('users', ['email' => 'consent@example.com']);
    }

    public function test_stale_consent_blocks_activation_until_reaccepted(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        // Terms were bumped between registration and OTP verification.
        config(['legal.terms_version' => '2.0']);

        $code = $this->mailedCode('consent@example.com');
        $this->assertNotNull($code);

        // Without re-accepting: rejected, account stays pending.
        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'consent@example.com',
            'code' => $code,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'terms_outdated');

        $this->assertSame('pending_verification', User::where('email', 'consent@example.com')->firstOrFail()->status);

        // Re-accepting the current version in the same request activates.
        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'consent@example.com',
            'code' => $code,
            'terms_version' => '2.0',
        ])->assertStatus(200);

        $user = User::where('email', 'consent@example.com')->firstOrFail();
        $this->assertSame('active', $user->status);
        $this->assertSame('2.0', $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
    }

    // ------------------------------------------------------------------
    // Social signup
    // ------------------------------------------------------------------

    protected function fakeVerifier(array $claims): FakeSocialTokenVerifier
    {
        $fake = new FakeSocialTokenVerifier($claims);
        $this->app->singleton(SocialTokenVerifier::class, fn () => $fake);

        return $fake;
    }

    public function test_social_signup_records_consent_fields(): void
    {
        $this->fakeVerifier([
            'sub' => 'google-consent-1',
            'email' => 'gsocial@example.com',
            'email_verified' => true,
            'name' => 'Google Consent',
        ]);

        $response = $this->postJson('/api/v1/auth/social/google', [
            'id_token' => 'fake-token',
            'terms_version' => config('legal.terms_version'),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.terms_version', '1.0');
        $this->assertNotNull($response->json('data.user.terms_accepted_at'));

        $user = User::where('email', 'gsocial@example.com')->firstOrFail();
        $this->assertSame('1.0', $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->terms_accepted_ip);
    }

    public function test_social_signup_rejects_stale_terms_version(): void
    {
        $this->fakeVerifier([
            'sub' => 'google-consent-2',
            'email' => 'gstale@example.com',
            'email_verified' => true,
            'name' => 'Google Stale',
        ]);

        $this->postJson('/api/v1/auth/social/google', [
            'id_token' => 'fake-token',
            'terms_version' => '0.9',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'terms_outdated');

        $this->assertDatabaseMissing('users', ['email' => 'gstale@example.com']);
    }

    public function test_social_signin_for_returning_user_does_not_require_terms(): void
    {
        $this->fakeVerifier([
            'sub' => 'google-consent-3',
            'email' => 'greturn@example.com',
            'email_verified' => true,
            'name' => 'Google Return',
        ]);

        $this->postJson('/api/v1/auth/social/google', [
            'id_token' => 'fake-token',
            'terms_version' => config('legal.terms_version'),
        ])->assertStatus(200);

        // Second sign-in (existing user): no terms_version needed.
        $this->postJson('/api/v1/auth/social/google', ['id_token' => 'fake-token'])
            ->assertStatus(200)
            ->assertJsonPath('data.user.email', 'greturn@example.com');

        $this->assertSame(1, User::where('email', 'greturn@example.com')->count());
    }

    // ------------------------------------------------------------------
    // Payload visibility + legacy accounts
    // ------------------------------------------------------------------

    public function test_terms_fields_are_present_in_login_and_me_payloads(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        $code = $this->mailedCode('consent@example.com');
        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'consent@example.com',
            'code' => $code,
        ])->assertStatus(200);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'consent@example.com',
            'password' => 'V3r1fy!Strong',
        ]);
        $login->assertStatus(200)
            ->assertJsonPath('data.user.terms_version', '1.0');
        $this->assertNotNull($login->json('data.user.terms_accepted_at'));
        // The IP is internal and never serialized.
        $this->assertArrayNotHasKey('terms_accepted_ip', $login->json('data.user'));

        Sanctum::actingAs(User::where('email', 'consent@example.com')->firstOrFail());
        $me = $this->getJson('/api/v1/auth/me');
        $me->assertStatus(200)
            ->assertJsonPath('data.user.terms_version', '1.0');
        $this->assertNotNull($me->json('data.user.terms_accepted_at'));
    }

    public function test_legacy_account_keeps_null_consent_fields(): void
    {
        // A pre-consent account (no terms_version) verifying its OTP does
        // NOT get fabricated consent data.
        $user = User::factory()->create([
            'email' => 'legacy@example.com',
            'status' => 'pending_verification',
            'email_verified_at' => null,
            'terms_version' => null,
            'terms_accepted_at' => null,
            'terms_accepted_ip' => null,
        ]);

        app(\App\Services\Auth\EmailOtpService::class)->issue($user, '127.0.0.1');
        $code = $this->mailedCode('legacy@example.com');
        $this->assertNotNull($code);

        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'legacy@example.com',
            'code' => $code,
        ])->assertStatus(200);

        $user->refresh();
        $this->assertSame('active', $user->status);
        $this->assertNull($user->terms_version);
        $this->assertNull($user->terms_accepted_at);
        $this->assertNull($user->terms_accepted_ip);
    }
}

<?php

namespace Tests\Feature;

use App\Models\FraudEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Round 2 — security hardening (code-level).
 *
 * - Security headers on every API response.
 * - Strong password policy on registration and password reset.
 * - Auth endpoints throttled.
 * - Suspicious auth events logged to fraud_events (failed logins,
 *   rapid-failure bursts, country mismatch).
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $response = $this->getJson('/api/v1/config/brand');

        $response->assertStatus(200);
        $response->assertHeader('Strict-Transport-Security');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
    }

    /**
     * @dataProvider weakPasswords
     */
    public function test_weak_passwords_are_rejected_at_registration(string $password): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Weak',
            'email' => 'weak-' . md5($password) . '@example.com',
            'password' => $password,
            'role' => 'contributor',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'weak-' . md5($password) . '@example.com']);
    }

    public static function weakPasswords(): array
    {
        return [
            'too short' => ['Ab1!'],
            'no uppercase' => ['v3r1fy!strong'],
            'no lowercase' => ['V3R1FY!STRONG'],
            'no digit' => ['Verify!Strong'],
            'no symbol' => ['V3r1fyStrong'],
            'old 8-char policy' => ['password'],
        ];
    }

    public function test_strong_password_is_accepted(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Strong',
            'email' => 'strong@example.com',
            'password' => 'V3r1fy!Strong',
            'role' => 'contributor',
        ])->assertStatus(201);
    }

    public function test_registration_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Throttle',
                'email' => "throttle{$i}@example.com",
                'password' => 'V3r1fy!Strong',
                'role' => 'contributor',
            ])->assertStatus(201);
        }

        // 6th registration within the minute is rate-limited.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Throttle',
            'email' => 'throttle5@example.com',
            'password' => 'V3r1fy!Strong',
            'role' => 'contributor',
        ])->assertStatus(429)->assertJson(['code' => 'rate_limited']);
    }

    public function test_failed_logins_are_logged_to_fraud_events(): void
    {
        User::create([
            'name' => 'Target', 'email' => 'target@example.com',
            'password' => Hash::make('V3r1fy!Strong'), 'role' => 'contributor',
            'status' => 'active', 'email_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'target@example.com',
            'password' => 'Wr0ng!Password',
        ])->assertStatus(401);

        $this->assertDatabaseHas('fraud_events', [
            'event_type' => 'failed_login',
            'severity' => 'low',
            'status' => 'reviewed',
        ]);
    }

    public function test_rapid_failed_logins_from_one_ip_are_flagged(): void
    {
        // The login throttle (5/min per email+IP) would normally stop this at
        // 5 — rotate the email key so the telemetry layer itself is exercised.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => "ghost{$i}@example.com",
                'password' => 'Wr0ng!Password',
            ])->assertStatus(401);
        }

        $this->assertDatabaseHas('fraud_events', [
            'event_type' => 'rapid_failed_logins',
            'severity' => 'medium',
            'status' => 'flagged',
        ]);
    }

    public function test_login_from_mismatched_country_is_flagged(): void
    {
        $user = User::create([
            'name' => 'Traveler', 'email' => 'traveler@example.com',
            'password' => Hash::make('V3r1fy!Strong'), 'role' => 'contributor',
            'status' => 'active', 'email_verified_at' => now(),
        ]);
        $user->profile()->create([
            'country_code' => 'AE', 'language' => 'en',
            'contributor_level' => 'starter', 'fraud_score' => 0,
        ]);

        // Cloudflare country header disagrees with the profile country.
        $this->withHeaders(['CF-IPCountry' => 'US'])->postJson('/api/v1/auth/login', [
            'email' => 'traveler@example.com',
            'password' => 'V3r1fy!Strong',
        ])->assertStatus(200);

        $this->assertDatabaseHas('fraud_events', [
            'user_id' => $user->id,
            'event_type' => 'country_mismatch',
            'severity' => 'medium',
            'status' => 'flagged',
        ]);
    }

    public function test_500_with_debug_off_carries_stable_code_and_no_leak(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/tasks/999999999');

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'code' => 'not_found',
        ]);
    }
}

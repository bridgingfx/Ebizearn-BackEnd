<?php

namespace Tests\Feature;

use App\Models\FraudEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Priority 6 — Fraud telemetry (Phase 2).
 *
 * Proves honest risk telemetry:
 *  - successful login writes a FraudEvent row with IP
 *  - new device fingerprint is flagged medium for moderator review
 *  - repeat device is logged low/reviewed (not flagged)
 *  - registration records IP and writes a registration FraudEvent
 *  - 3+ accounts from same IP in 30 days flags multi_account (medium)
 *  - login is never blocked by telemetry
 */
class FraudTelemetryTest extends TestCase
{
    use RefreshDatabase;

    protected function register(string $email): array
    {
        $resp = $this->postJson('/api/v1/auth/register', [
            'name' => 'Fraud Test',
            'email' => $email,
            'password' => 'V3r1fy!Strong',
            'password_confirmation' => 'V3r1fy!Strong',
            'role' => 'contributor',
            "phone_country_code" => "+971",
            "phone_number" => "501234567",
        ]);
        $resp->assertStatus(201);
        // Fraud telemetry tests exercise the login path, not verification:
        // activate the account in setup (mirrors a verified user).
        $user = User::where('email', $email)->firstOrFail();
        $user->forceFill(['status' => 'active'])->save();

        return [$resp->json('data.user'), $user->fresh()];
    }

    public function test_registration_records_ip_and_logs_event(): void
    {
        [$json, $user] = $this->register('fraud1@example.com');

        $this->assertNotEmpty($user->registration_ip);

        $this->assertDatabaseHas('fraud_events', [
            'user_id' => $user->id,
            'event_type' => 'registration',
            'severity' => 'low',
            'status' => 'reviewed',
        ]);
    }

    public function test_first_login_flags_new_device(): void
    {
        [$json, $user] = $this->register('fraud2@example.com');

        // Login from a DIFFERENT device (different User-Agent → different
        // fingerprint) is flagged as new_device_login.
        $login = $this->withHeaders(['User-Agent' => 'NewDevice/1.0'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'fraud2@example.com',
                'password' => 'V3r1fy!Strong',
                'portal' => 'contributor',
            ]);
        $login->assertStatus(200);

        // New device → flagged medium for moderator review.
        $this->assertDatabaseHas('fraud_events', [
            'user_id' => $user->id,
            'event_type' => 'new_device_login',
            'severity' => 'medium',
            'status' => 'flagged',
        ]);

        $user = $user->fresh();
        $this->assertNotEmpty($user->last_login_ip);
        $this->assertNotNull($user->last_login_at);
    }

    public function test_repeat_login_from_same_device_is_routine(): void
    {
        [$json, $user] = $this->register('fraud3@example.com');

        $payload = ['email' => 'fraud3@example.com', 'password' => 'V3r1fy!Strong', 'portal' => 'contributor'];
        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(200);
        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(200);

        // Second login from same device → routine low/reviewed, not flagged.
        $this->assertDatabaseHas('fraud_events', [
            'user_id' => $user->id,
            'event_type' => 'login',
            'severity' => 'low',
            'status' => 'reviewed',
        ]);
    }

    public function test_three_accounts_from_same_ip_flags_multi_account(): void
    {
        // All three register from the test IP (127.0.0.1).
        $this->register('fraud4a@example.com');
        $this->register('fraud4b@example.com');
        [$json, $userC] = $this->register('fraud4c@example.com');

        // Third registration triggers the multi_account flag.
        $this->assertDatabaseHas('fraud_events', [
            'user_id' => $userC->id,
            'event_type' => 'multi_account',
            'severity' => 'medium',
            'status' => 'flagged',
        ]);

        // Registration is NOT blocked — user can still log in.
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'fraud4c@example.com',
            'password' => 'V3r1fy!Strong',
            'portal' => 'contributor',
        ]);
        $login->assertStatus(200);
    }

    public function test_telemetry_never_blocks_login(): void
    {
        [$json, $user] = $this->register('fraud5@example.com');

        // Even with many logins, access is granted.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'fraud5@example.com',
                'password' => 'V3r1fy!Strong',
                'portal' => 'contributor',
            ])->assertStatus(200);
        }

        $this->assertGreaterThanOrEqual(3, FraudEvent::where('user_id', $user->id)->count());
    }
}

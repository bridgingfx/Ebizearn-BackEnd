<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Signup hardening — PUT /api/v1/profile (phone save for the
 * post-Google-signup phone step).
 *
 * Covers: happy path (E.164 persisted on users.phone + mirrored to the
 * legacy profiles.phone column), dial-code allow-list rejection, number
 * format rejection, and the auth requirement.
 */
class ProfilePhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Phone Tester',
            'email' => 'phone@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
            'phone' => null,
        ], $overrides));
    }

    public function test_authenticated_user_can_save_phone(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $res = $this->putJson('/api/v1/profile', [
            'phone_country_code' => '+971',
            'phone_number' => '501234567',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.phone', '+971501234567');

        $this->assertSame('+971501234567', $user->fresh()->phone);
        // Legacy column stays in sync for the profile.phone read path.
        $this->assertSame('+971501234567', $user->fresh()->profile->getRawOriginal('phone'));
    }

    public function test_phone_save_rejects_unknown_dial_code(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $res = $this->putJson('/api/v1/profile', [
            'phone_country_code' => '+999',
            'phone_number' => '501234567',
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertNull($user->fresh()->phone);
    }

    public function test_phone_save_rejects_bad_number(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $res = $this->putJson('/api/v1/profile', [
            'phone_country_code' => '+971',
            'phone_number' => '12', // too short
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertNull($user->fresh()->phone);
    }

    public function test_phone_save_requires_authentication(): void
    {
        $res = $this->putJson('/api/v1/profile', [
            'phone_country_code' => '+971',
            'phone_number' => '501234567',
        ]);

        $res->assertStatus(401);
    }

    public function test_phone_can_be_updated_again(): void
    {
        $user = $this->makeUser(['phone' => '+14155552671']);
        Sanctum::actingAs($user);

        $res = $this->putJson('/api/v1/profile', [
            'phone_country_code' => '91',
            'phone_number' => '9876543210',
        ]);

        $res->assertStatus(200);
        $this->assertSame('+919876543210', $user->fresh()->phone);
    }
}

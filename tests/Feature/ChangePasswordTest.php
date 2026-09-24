<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PUT /api/v1/profile/password — signed-in password change.
 */
class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'Old!Passw0rd99';
    private const NEW = 'N3w!Passw0rd77';

    protected function makeUser(): User
    {
        return User::factory()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Pw Tester',
            'email' => 'pw' . Str::random(5) . '@example.com',
            'password' => Hash::make(self::OLD),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_user_can_change_password_and_other_sessions_are_revoked(): void
    {
        $user = $this->makeUser();
        $current = $user->createToken('this-device')->plainTextToken;
        $user->createToken('other-device');

        $this->withHeader('Authorization', "Bearer {$current}")
            ->putJson('/api/v1/profile/password', [
                'current_password' => self::OLD,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password));
        // Only this session survives.
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_changed', 'entity_id' => $user->id]);

        // The new password logs in.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::NEW])->assertOk();
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'nope',
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])->assertStatus(422)->assertJsonPath('errors.current_password.0', 'Your current password is incorrect.');

        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password));
    }

    public function test_weak_mismatched_or_same_password_is_rejected(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('t')->plainTextToken;
        $put = fn (array $body) => $this->withHeader('Authorization', "Bearer {$token}")->putJson('/api/v1/profile/password', $body);

        $put(['current_password' => self::OLD, 'password' => 'weak', 'password_confirmation' => 'weak'])->assertStatus(422);
        $put(['current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => 'Different!123'])->assertStatus(422);
        $put(['current_password' => self::OLD, 'password' => self::OLD, 'password_confirmation' => self::OLD])->assertStatus(422);
    }

    public function test_requires_authentication(): void
    {
        $this->putJson('/api/v1/profile/password', [])->assertStatus(401);
    }
}

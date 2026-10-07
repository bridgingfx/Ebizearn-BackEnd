<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Session policy on login + the public task feed.
 *
 * - staff (moderator / admin / superadmin) keep every live session when
 *   they sign in elsewhere; contributors / businesses stay single-session
 * - GET /tasks is public: it must work for guests and signed-in users alike
 */
class SessionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role, string $email): User
    {
        // forceCreate: role / status / email_verified_at are not fillable.
        return User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' User',
            'email' => $email,
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function login(string $email): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'V3r1fy!Strong'])
            ->assertStatus(200);
    }

    public function test_staff_login_keeps_other_live_sessions(): void
    {
        foreach (['superadmin', 'admin', 'moderator'] as $role) {
            $user = $this->makeUser($role, "{$role}-multi@example.com");

            $this->login($user->email); // system A
            $this->login($user->email); // system B

            $this->assertSame(2, $user->fresh()->tokens()->count(), "{$role} must keep both sessions");
        }
    }

    public function test_staff_login_prunes_only_expired_sessions(): void
    {
        $admin = $this->makeUser('admin', 'admin-prune@example.com');
        $stale = $admin->createToken('auth_token')->accessToken;
        $stale->forceFill(['created_at' => now()->subMinutes((int) config('sanctum.expiration') + 1)])->save();

        $this->login($admin->email);

        $this->assertSame(1, $admin->fresh()->tokens()->count());
        $this->assertNull($admin->fresh()->tokens()->find($stale->id));
    }

    public function test_contributor_login_stays_single_session(): void
    {
        $contributor = $this->makeUser('contributor', 'contrib-single@example.com');

        $this->login($contributor->email);
        $this->login($contributor->email);

        $this->assertSame(1, $contributor->fresh()->tokens()->count());
    }

    public function test_public_task_feed_works_for_guests(): void
    {
        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonPath('success', true);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2: Super Admin hidden ops surface.
 * - `php artisan superadmin:create` is the only superadmin creation path
 *   (interactive, idempotent).
 * - POST /api/v1/ops/admins creates admin/moderator accounts ONLY for
 *   superadmin callers, with permission assignment.
 * - Every other role gets 403 on the whole /ops prefix.
 */
class OpsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeSuperAdmin(string $email = 'ops@example.com'): User
    {
        return User::create([
            'name' => 'Ops',
            'email' => $email,
            'password' => Hash::make('supersecretpassword123'),
            'role' => 'superadmin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_superadmin_create_command_is_idempotent(): void
    {
        $exit = Artisan::call('superadmin:create', [
            '--email' => 'root@example.com',
            '--password' => 'supersecretpassword123',
        ]);
        $this->assertSame(0, $exit);

        $user = User::where('email', 'root@example.com')->firstOrFail();
        $this->assertTrue($user->isSuperAdmin());

        // Second run: no duplicate; the password is rotated instead.
        $exit2 = Artisan::call('superadmin:create', [
            '--email' => 'root@example.com',
            '--password' => 'anotherpassword123',
            '--no-interaction' => true,
        ]);
        $this->assertSame(0, $exit2);
        $this->assertSame(1, User::where('email', 'root@example.com')->count());
        $this->assertTrue(Hash::check('anotherpassword123', User::where('email', 'root@example.com')->firstOrFail()->password));

        // Audit trail written.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'superadmin.created',
            'entity_type' => User::class,
            'entity_id' => $user->id,
        ]);
    }

    public function test_superadmin_create_command_rejects_short_password(): void
    {
        $exit = Artisan::call('superadmin:create', [
            '--email' => 'weak@example.com',
            '--password' => 'short',
        ]);
        $this->assertSame(1, $exit);
        $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
    }

    public function test_ops_admin_creation_requires_superadmin(): void
    {
        foreach (['contributor', 'business', 'admin', 'moderator'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            Sanctum::actingAs($user);

            $this->getJson('/api/v1/ops/admins')->assertStatus(403);
            $this->postJson('/api/v1/ops/admins', [
                'name' => 'Eve',
                'email' => "eve-{$role}@example.com",
                'password' => 'adminpassword123',
                'role' => 'admin',
            ])->assertStatus(403);

            $this->assertDatabaseMissing('users', ['email' => "eve-{$role}@example.com"]);
        }
    }

    public function test_superadmin_can_create_admin_with_permissions(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->postJson('/api/v1/ops/admins', [
            'name' => 'Alice Admin',
            'email' => 'alice-admin@example.com',
            'password' => 'Admin!Strong1',
            'role' => 'admin',
            'permissions' => ['review_submissions', 'manage_users'],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.role', 'admin');

        $admin = User::where('email', 'alice-admin@example.com')->firstOrFail();
        $this->assertTrue($admin->hasPermission('review_submissions'));
        $this->assertTrue($admin->hasPermission('manage_users'));
        // Contributor-only capability: granted to no staff role or user.
        $this->assertFalse($admin->hasPermission('request_withdrawals'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.created',
            'entity_type' => User::class,
            'entity_id' => $admin->id,
        ]);
    }

    public function test_ops_cannot_create_superadmin_via_api(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->postJson('/api/v1/ops/admins', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'adminpassword123',
            'role' => 'superadmin',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_superadmin_can_update_staff_permissions_but_not_own(): void
    {
        $super = $this->makeSuperAdmin();
        Sanctum::actingAs($super);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->patchJson("/api/v1/ops/admins/{$admin->id}/permissions", [
            'permissions' => ['review_submissions', 'handle_disputes'],
        ])->assertStatus(200);

        $this->assertTrue($admin->fresh()->hasPermission('review_submissions'));
        // Direct grants union with the admin role's default grants, so
        // manage_users (a role default) still holds; request_withdrawals (a
        // contributor capability) was never granted to admins anywhere.
        $this->assertTrue($admin->fresh()->hasPermission('manage_users'));
        $this->assertFalse($admin->fresh()->hasPermission('request_withdrawals'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.permissions_updated',
            'entity_type' => User::class,
            'entity_id' => $admin->id,
        ]);

        // Cannot strip your own access.
        $this->patchJson("/api/v1/ops/admins/{$super->id}/permissions", [
            'permissions' => [],
        ])->assertStatus(422);
    }

    public function test_role_default_grants_are_seeded(): void
    {
        $moderator = User::factory()->create(['role' => 'moderator']);
        $this->assertTrue($moderator->hasPermission('review_submissions'));
        $this->assertFalse($moderator->hasPermission('manage_settings'));

        $contributor = User::factory()->create(['role' => 'contributor']);
        $this->assertFalse($contributor->hasPermission('review_submissions'));

        // Super admin holds everything implicitly.
        $this->assertTrue($this->makeSuperAdmin('root2@example.com')->hasPermission('manage_settings'));
    }

    public function test_ops_settings_endpoints_require_superadmin(): void
    {
        $contributor = User::factory()->create(['role' => 'contributor']);
        Sanctum::actingAs($contributor);

        $this->getJson('/api/v1/ops/countries')->assertStatus(403);
        $this->getJson('/api/v1/ops/withdrawal-rules')->assertStatus(403);
        $this->getJson('/api/v1/ops/audit-logs')->assertStatus(403);
    }

    public function test_staff_password_must_meet_strong_policy(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        // 12+ chars but no mixed classes: weaker than what public signup
        // requires, so it must be rejected for staff accounts.
        $this->postJson('/api/v1/ops/admins', [
            'name' => 'Weak Staff',
            'email' => 'weak-staff@example.com',
            'password' => 'adminpassword123',
            'role' => 'admin',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'weak-staff@example.com']);
    }

    /**
     * PATCH /api/v1/admin/users/{id}/status guards:
     *  - nobody can change their own status (self-suspension = lockout);
     *  - an admin cannot touch staff accounts (admin/moderator/superadmin)
     *    — staff management is Super Admin only;
     *  - suspending revokes every existing Sanctum token immediately, so
     *    the suspension actually locks the account out (there is no
     *    per-request status check — login only blocks the NEXT login).
     */
    public function test_user_status_change_guards(): void
    {
        $super = $this->makeSuperAdmin('status-root@example.com');
        $admin = User::factory()->create(['role' => 'admin']);
        $contributor = User::factory()->create(['role' => 'contributor']);

        // An admin cannot change their own status…
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/users/{$admin->id}/status", ['status' => 'suspended'])
            ->assertStatus(422);
        $this->assertSame('active', $admin->fresh()->status);

        // …nor a superadmin's…
        $this->patchJson("/api/v1/admin/users/{$super->id}/status", ['status' => 'suspended'])
            ->assertStatus(403);
        $this->assertSame('active', $super->fresh()->status);

        // …but a regular user account is fair game, and suspension kills
        // their existing sessions on the spot.
        $contributor->createToken('session_one');
        $this->assertSame(1, $contributor->tokens()->count());

        $this->patchJson("/api/v1/admin/users/{$contributor->id}/status", ['status' => 'suspended'])
            ->assertStatus(200);
        $this->assertSame('suspended', $contributor->fresh()->status);
        $this->assertSame(0, $contributor->fresh()->tokens()->count());

        // Re-activating works without re-issuing anything.
        $this->patchJson("/api/v1/admin/users/{$contributor->id}/status", ['status' => 'active'])
            ->assertStatus(200);
        $this->assertSame('active', $contributor->fresh()->status);

        // A superadmin CAN manage staff statuses (but still not their own).
        Sanctum::actingAs($super);
        $this->patchJson("/api/v1/admin/users/{$admin->id}/status", ['status' => 'suspended'])
            ->assertStatus(200);
        $this->assertSame('suspended', $admin->fresh()->status);

        $this->patchJson("/api/v1/admin/users/{$super->id}/status", ['status' => 'suspended'])
            ->assertStatus(422);
        $this->assertSame('active', $super->fresh()->status);
    }

    /**
     * PATCH /api/v1/admin/system-settings validates the key (a missing key
     * previously 500'd inside SystemSetting::set()) and audits the change.
     */
    public function test_system_setting_update_validates_and_audits(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin('settings-root@example.com'));

        $this->patchJson('/api/v1/admin/system-settings', ['value' => 'x'])
            ->assertStatus(422);

        $this->patchJson('/api/v1/admin/system-settings', [
            'key' => 'platform_name',
            'value' => 'Test Platform',
        ])->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'system_setting.updated',
            'entity_type' => \App\Models\SystemSetting::class,
        ]);
    }

    public function test_audit_log_is_append_only(): void
    {
        $log = AuditLog::create([
            'actor_id' => null,
            'action' => 'test.action',
            'entity_type' => User::class,
            'entity_id' => 1,
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $log->update(['action' => 'test.tampered']);
    }

    public function test_audit_log_delete_is_blocked(): void
    {
        $log = AuditLog::create([
            'actor_id' => null,
            'action' => 'test.action',
            'entity_type' => User::class,
            'entity_id' => 1,
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $log->delete();
    }
}

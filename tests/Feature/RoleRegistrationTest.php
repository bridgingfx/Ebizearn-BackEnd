<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2: public registration is contributor/business only.
 * Privileged roles must be rejected with 422 — admins, moderators and
 * super admins are created exclusively via the ops API / artisan command.
 */
class RoleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * @dataProvider privilegedRoles
     */
    public function test_privileged_roles_are_rejected_at_registration(string $role): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Mallory',
            'email' => "mallory-{$role}@example.com",
            'password' => 'V3r1fy!Strong',
            'role' => $role,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => "mallory-{$role}@example.com"]);
    }

    public static function privilegedRoles(): array
    {
        return [
            'admin' => ['admin'],
            'moderator' => ['moderator'],
            'superadmin' => ['superadmin'],
            'super_admin variant' => ['super_admin'],
        ];
    }

    public function test_contributor_and_business_can_self_register(): void
    {
        $contributor = $this->postJson('/api/v1/auth/register', [
            'name' => 'Cara',
            'email' => 'cara@example.com',
            'password' => 'V3r1fy!Strong',
            'role' => 'contributor',
            "phone_country_code" => "+971",
            "phone_number" => "501234567",
            "terms_version" => "1.0",
        ]);
        $contributor->assertStatus(201)->assertJsonPath('data.user.role', 'contributor');

        $business = $this->postJson('/api/v1/auth/register', [
            'name' => 'Biz Owner',
            'email' => 'biz@example.com',
            'password' => 'V3r1fy!Strong',
            'role' => 'business',
            "phone_country_code" => "+971",
            "phone_number" => "501234567",
            "terms_version" => "1.0",
            'company_name' => 'Acme LLC',
        ]);
        $business->assertStatus(201)->assertJsonPath('data.user.role', 'business');

        $this->assertSame('contributor', User::where('email', 'cara@example.com')->first()->role);
        $this->assertSame('business', User::where('email', 'biz@example.com')->first()->role);
    }

    public function test_authenticated_users_cannot_reach_other_roles_routes(): void
    {
        $contributor = User::factory()->create(['role' => 'contributor']);
        Sanctum::actingAs($contributor);

        $this->getJson('/api/v1/business/dashboard')->assertStatus(403);
        $this->getJson('/api/v1/ops/admins')->assertStatus(403);
    }
}

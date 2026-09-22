<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Portal separation + role-gate enforcement.
 *
 * - login accepts an optional `portal` param; on role/portal mismatch it
 *   403s WITHOUT revoking existing tokens or minting a new one
 * - every role-scoped route group requires its role: cross-role tokens 403,
 *   missing tokens 401
 */
class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role, string $email): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' User',
            'email' => $email,
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_contributor_token_is_forbidden_on_admin_routes(): void
    {
        $contributor = $this->makeUser('contributor', 'portal-contrib@example.com');
        Sanctum::actingAs($contributor);

        $this->getJson('/api/v1/admin/dashboard')->assertStatus(403);
    }

    public function test_business_token_is_forbidden_on_moderator_routes(): void
    {
        $business = $this->makeUser('business', 'portal-biz@example.com');
        Sanctum::actingAs($business);

        $this->getJson('/api/v1/moderator/verification-queue')->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/contributor/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/wallet')->assertStatus(401);
    }

    public function test_login_with_mismatched_portal_403s_without_token_changes(): void
    {
        $contributor = $this->makeUser('contributor', 'portal-login@example.com');

        // An existing session must survive the rejected attempt.
        $existingToken = $contributor->createToken('old_token')->plainTextToken;
        $tokensBefore = $contributor->tokens()->count();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'portal-login@example.com',
            'password' => 'V3r1fy!Strong',
            'portal' => 'business',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'This account does not belong to the business portal. Please use the correct sign-in.',
        ]);

        // No token issued for this attempt.
        $this->assertArrayNotHasKey('data', $response->json());

        // Existing tokens untouched; no new token minted.
        $this->assertEquals($tokensBefore, $contributor->fresh()->tokens()->count());
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $contributor->id,
            'name' => 'old_token',
        ]);
        $this->assertTrue((bool) $existingToken);
    }

    public function test_login_with_matching_portal_succeeds(): void
    {
        $contributor = $this->makeUser('contributor', 'portal-match@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'portal-match@example.com',
            'password' => 'V3r1fy!Strong',
            'portal' => 'contributor',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_without_portal_param_still_works(): void
    {
        $business = $this->makeUser('business', 'portal-noparam@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'portal-noparam@example.com',
            'password' => 'V3r1fy!Strong',
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_rejects_unknown_portal_value(): void
    {
        $contributor = $this->makeUser('contributor', 'portal-badval@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'portal-badval@example.com',
            'password' => 'V3r1fy!Strong',
            'portal' => 'owner',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Priority 1 — full role grid with REAL tokens (via the login API).
     *
     * NOTE: one test method per role. Laravel's test app instance is shared
     * across requests inside a single test method and Sanctum's guard caches
     * the first authenticated user, so cross-role requests must live in
     * separate methods to get a fresh guard per role.
     */
    protected function loginAndGetToken(string $email, string $password = 'V3r1fy!Strong'): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
        $response->assertStatus(200);
        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        return $token;
    }

    protected function makeBusinessUser(string $email): User
    {
        $user = $this->makeUser('business', $email);
        \App\Models\Business::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => $user->id,
            'company_name' => 'Grid Test Co',
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_contributor_grid_with_real_token(): void
    {
        $this->makeUser('contributor', 'grid-contrib@example.com');
        $token = $this->loginAndGetToken('grid-contrib@example.com');
        $auth = ['Authorization' => 'Bearer ' . $token];

        // CAN: own contributor APIs
        $this->withHeaders($auth)->getJson('/api/v1/contributor/dashboard')->assertStatus(200);
        $this->withHeaders($auth)->getJson('/api/v1/wallet')->assertStatus(200);
        $this->withHeaders($auth)->getJson('/api/v1/tasks')->assertStatus(200);

        // CANNOT: business, moderator, admin, ops
        $this->withHeaders($auth)->getJson('/api/v1/business/dashboard')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/v1/moderator/verification-queue')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/v1/admin/dashboard')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/v1/ops/platform-settings')->assertStatus(403);
    }

    public function test_business_grid_with_real_token(): void
    {
        $this->makeBusinessUser('grid-biz@example.com');
        $token = $this->loginAndGetToken('grid-biz@example.com');
        $auth = ['Authorization' => 'Bearer ' . $token];

        // CAN: own business APIs
        $this->withHeaders($auth)->getJson('/api/v1/business/dashboard')->assertStatus(200);
        $this->withHeaders($auth)->getJson('/api/v1/business/campaigns')->assertStatus(200);

        // CANNOT: contributor, moderator, admin, ops
        $this->withHeaders($auth)->getJson('/api/v1/contributor/dashboard')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/v1/wallet')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/v1/moderator/verification-queue')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/v1/admin/dashboard')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/v1/ops/platform-settings')->assertStatus(403);
    }

    public function test_moderator_grid_with_real_token(): void
    {
        $this->seed(); // roles + default permission grants (review_submissions)
        $this->makeUser('moderator', 'grid-mod@example.com');
        $token = $this->loginAndGetToken('grid-mod@example.com');
        $auth = ['Authorization' => 'Bearer ' . $token];

        // CAN: review endpoints (permission-gated)
        $this->withHeaders($auth)->getJson('/api/v1/moderator/verification-queue')->assertStatus(200);
        $this->withHeaders($auth)->getJson('/api/v1/moderator/fraud-alerts')->assertStatus(200);

        // CANNOT: super-admin ops, admin, contributor, business
        $this->withHeaders($auth)->getJson('/api/v1/ops/platform-settings')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/v1/admin/dashboard')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/v1/contributor/dashboard')->assertStatus(403);
        $this->withHeaders($auth)->getJson('/api/v1/business/dashboard')->assertStatus(403);
    }

    public function test_superadmin_grid_with_real_token(): void
    {
        $this->seed();
        $this->makeUser('superadmin', 'grid-root@example.com');
        $token = $this->loginAndGetToken('grid-root@example.com');
        $auth = ['Authorization' => 'Bearer ' . $token];

        // CAN: everything, including the hidden /ops prefix
        $this->withHeaders($auth)->getJson('/api/v1/ops/platform-settings')->assertStatus(200);
        $this->withHeaders($auth)->getJson('/api/v1/admin/dashboard')->assertStatus(200);
        $this->withHeaders($auth)->getJson('/api/v1/moderator/verification-queue')->assertStatus(200);
    }
}

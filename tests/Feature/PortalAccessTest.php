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
            'password' => Hash::make('password123'),
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
            'password' => 'password123',
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
            'password' => 'password123',
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
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_rejects_unknown_portal_value(): void
    {
        $contributor = $this->makeUser('contributor', 'portal-badval@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'portal-badval@example.com',
            'password' => 'password123',
            'portal' => 'owner',
        ]);

        $response->assertStatus(422);
    }
}

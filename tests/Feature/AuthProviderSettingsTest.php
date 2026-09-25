<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\SocialTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Super Admin → Settings → Social sign-in: turn Google / Apple on or off and
 * set their client IDs; the login pages and the API follow the switch.
 */
class AuthProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function user(string $role): User
    {
        return User::factory()->create([
            'uuid' => (string) Str::uuid(),
            'email' => $role . Str::random(5) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function fakeGoogle(): void
    {
        $this->app->singleton(SocialTokenVerifier::class, fn () => new class implements SocialTokenVerifier {
            public function verifyGoogle(string $idToken): array
            {
                return ['sub' => 'g-1', 'email' => 'person@example.com', 'email_verified' => true, 'name' => 'Person'];
            }

            public function verifyApple(string $idToken): array
            {
                return ['sub' => 'a-1', 'email' => 'apple@example.com', 'email_verified' => true, 'name' => null];
            }
        });
    }

    public function test_env_client_ids_are_used_until_super_admin_saves_settings(): void
    {
        $this->getJson('/api/v1/config/auth-providers')
            ->assertOk()
            ->assertJsonPath('data.google.enabled', true)
            ->assertJsonPath('data.google.client_id', '123456-testclient.apps.googleusercontent.com')
            ->assertJsonPath('data.apple.enabled', true);
    }

    public function test_super_admin_turns_providers_off_and_on(): void
    {
        $this->fakeGoogle();
        Sanctum::actingAs($this->user('superadmin'));

        $this->putJson('/api/v1/admin/auth-providers', [
            'google' => ['enabled' => false, 'client_id' => '123456-testclient.apps.googleusercontent.com'],
            'apple' => ['enabled' => false, 'client_id' => ''],
        ])->assertOk()->assertJsonFragment(['message' => 'Saved. Social sign-in is off — only email sign-in is shown.']);

        $this->getJson('/api/v1/config/auth-providers')
            ->assertJsonPath('data.google.enabled', false)
            ->assertJsonPath('data.google.client_id', null)
            ->assertJsonPath('data.apple.enabled', false);

        // Turned off = refused by the API too, not just hidden.
        $this->postJson('/api/v1/auth/social/google', ['id_token' => 't'])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Google sign-in is turned off. Please use your email and password.']);

        // Turn Google back on with a new client ID.
        $this->putJson('/api/v1/admin/auth-providers', [
            'google' => ['enabled' => true, 'client_id' => '999-newclient.apps.googleusercontent.com'],
            'apple' => ['enabled' => false],
        ])->assertOk()->assertJsonPath('data.google.source', 'settings');

        $this->getJson('/api/v1/config/auth-providers')
            ->assertJsonPath('data.google.enabled', true)
            ->assertJsonPath('data.google.client_id', '999-newclient.apps.googleusercontent.com');
        $this->assertSame('999-newclient.apps.googleusercontent.com', app(\App\Services\Auth\SocialAuthSettings::class)->clientId('google'));

        $this->postJson('/api/v1/auth/social/google', ['id_token' => 't', 'terms_version' => '1.0'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.auth_providers_updated']);
    }

    public function test_validation_and_access(): void
    {
        Sanctum::actingAs($this->user('superadmin'));

        $this->putJson('/api/v1/admin/auth-providers', [
            'google' => ['enabled' => true, 'client_id' => 'not-a-google-id'],
            'apple' => ['enabled' => false],
        ])->assertStatus(422)->assertJsonValidationErrors('google.client_id');

        config(['services.apple.client_id' => null]);
        $this->putJson('/api/v1/admin/auth-providers', [
            'google' => ['enabled' => false],
            'apple' => ['enabled' => true, 'client_id' => ''],
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Apple sign-in needs a client ID before it can be turned on.']);

        // Admins (even with manage_settings) cannot change sign-in providers.
        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/v1/admin/auth-providers')->assertForbidden();
        $this->putJson('/api/v1/admin/auth-providers', ['google' => ['enabled' => false], 'apple' => ['enabled' => false]])->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\DemoRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public demo-request submissions + admin triage listing.
 */
class DemoRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ayesha Khan',
            'email' => 'ayesha@example.com',
            'company' => 'Acme Growth Labs',
            'message' => 'We would like a demo of the campaign wizard for our marketing team.',
        ], $overrides);
    }

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

    public function test_valid_demo_request_returns_201_and_persists(): void
    {
        $response = $this->postJson('/api/v1/demo-requests', $this->payload());

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Demo request received. Our team will be in touch shortly.',
        ]);

        $this->assertDatabaseHas('demo_requests', [
            'email' => 'ayesha@example.com',
            'company' => 'Acme Growth Labs',
            'status' => 'new',
        ]);
    }

    public function test_demo_request_validation_rejects_bad_email_and_short_message(): void
    {
        $this->postJson('/api/v1/demo-requests', $this->payload(['email' => 'not-an-email']))
            ->assertStatus(422);

        $this->postJson('/api/v1/demo-requests', $this->payload(['message' => 'short']))
            ->assertStatus(422);

        $this->postJson('/api/v1/demo-requests', $this->payload(['name' => null]))
            ->assertStatus(422);
    }

    public function test_contributor_cannot_list_demo_requests(): void
    {
        $contributor = $this->makeUser('contributor', 'demo-contrib@example.com');
        Sanctum::actingAs($contributor);

        $this->getJson('/api/v1/admin/demo-requests')->assertStatus(403);
    }

    public function test_admin_can_list_demo_requests_latest_first(): void
    {
        DemoRequest::create($this->payload(['email' => 'first@example.com', 'name' => 'First']));
        DemoRequest::create($this->payload(['email' => 'second@example.com', 'name' => 'Second']));
        // Distinct timestamps: same-second rows would leave latest-first
        // ordering undefined on created_at.
        DemoRequest::where('email', 'second@example.com')
            ->update(['created_at' => now()->addMinute()]);

        $admin = $this->makeUser('admin', 'demo-admin@example.com');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/demo-requests');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 2);
        // Latest first.
        $this->assertEquals('second@example.com', $response->json('data.0.email'));
    }
}

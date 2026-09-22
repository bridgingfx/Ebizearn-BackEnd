<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API error-shape consistency + no information leakage.
 *
 * - Every error answers JSON {success:false, message[, errors]} — never HTML,
 *   never a stack trace.
 * - With APP_DEBUG=false a 500 answers "Server Error" instead of the raw
 *   exception message (SQL, paths, internals stay in the logs).
 * - Validation failures (both framework-thrown and controller-built) carry
 *   a field-level `errors` map and a clean message.
 */
class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // A route that always explodes — lets us verify the renderer's
        // behaviour for genuine 500s under both debug settings.
        Route::get('/_test/boom', function () {
            throw new \RuntimeException("SQLSTATE[HY000]: db password='s3cret' leaked at /var/www/app/Secret.php:42");
        });
    }

    public function test_500_with_debug_off_hides_internals(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/_test/boom');

        $response->assertStatus(500)
            ->assertJson(['success' => false, 'message' => 'Server Error']);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('s3cret', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('Secret.php', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
    }

    public function test_500_with_debug_on_keeps_developer_message(): void
    {
        config(['app.debug' => true]);

        $response = $this->getJson('/_test/boom');

        $response->assertStatus(500)->assertJson(['success' => false]);
        $this->assertStringContainsString('SQLSTATE', (string) $response->json('message'));
    }

    public function test_validation_errors_have_consistent_shape(): void
    {
        // Controller-built 422 (manual Validator).
        $response = $this->postJson('/api/v1/auth/register', ['email' => 'not-an-email']);
        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation error'])
            ->assertJsonStructure(['success', 'message', 'errors']);

        $errors = $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('password', $errors);
        // Field messages are clean strings, not dumps.
        $this->assertStringNotContainsString('Exception', (string) json_encode($errors));
    }

    public function test_not_found_and_forbidden_shapes(): void
    {
        // No token -> JSON 401 (checked before any actingAs in this test).
        $this->getJson('/api/v1/wallet')
            ->assertStatus(401)
            ->assertJson(['success' => false]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Err Contributor',
            'email' => 'err-contrib@example.com',
            'password' => Hash::make('password123'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        // Unknown resource -> JSON 404, not an HTML page.
        $this->getJson('/api/v1/tasks/999999')
            ->assertStatus(404)
            ->assertJson(['success' => false]);

        // Wrong-role access -> JSON 403.
        $this->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_business_error_messages_stay_user_friendly(): void
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Err Contributor 2',
            'email' => 'err-contrib2@example.com',
            'password' => Hash::make('password123'),
            'role' => 'contributor',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        config(['app.debug' => false]);

        // Below-minimum withdrawal is a business rule (422/400 range), so the
        // honest message is kept even with debug off.
        $response = $this->postJson('/api/v1/wallet/withdraw', [
            'amount_cents' => 1,
            'payout_method' => 'bank_transfer',
            'payout_details' => ['account' => '1'],
        ]);

        $this->assertContains($response->status(), [400, 422]);
        $this->assertSame(false, $response->json('success'));
        $this->assertNotEmpty($response->json('message'));
    }
}

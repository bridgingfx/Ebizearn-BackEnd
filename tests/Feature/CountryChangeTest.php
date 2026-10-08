<?php

namespace Tests\Feature;

use App\Models\CountryChangeRequest;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Residence-country change: a client's edit becomes a pending request;
 * staff approval switches the country and forces KYC for the new country
 * (tasks locked until approved); rejection leaves everything unchanged.
 */
class CountryChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role, array $profile = []): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' User',
            'email' => $role . Str::random(5) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Profile::forceCreate(array_merge([
            'user_id' => $user->id,
            'country_code' => 'AE',
            'kyc_status' => 'verified',
            'kyc_country_code' => 'AE',
        ], $profile));

        return $user;
    }

    protected function staff(): User
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        return User::where('role', 'superadmin')->first() ?? $this->makeUser('superadmin');
    }

    public function test_client_country_edit_becomes_a_pending_request(): void
    {
        $contributor = $this->makeUser('contributor');
        Sanctum::actingAs($contributor);

        $this->putJson('/api/v1/profile', ['country_code' => 'in', 'city' => 'Chennai'])
            ->assertOk()
            ->assertJsonPath('data.country_change_request.to_country', 'IN');

        $profile = $contributor->profile()->first();
        $this->assertSame('AE', $profile->country_code, 'country must not change before approval');
        $this->assertSame('verified', $profile->kyc_status);
        $this->assertSame('Chennai', $profile->city, 'other fields still save');
        $this->getJson('/api/v1/profile/country-change')->assertJsonPath('data.status', 'pending');
    }

    public function test_second_different_request_is_refused_while_one_is_pending(): void
    {
        Sanctum::actingAs($this->makeUser('contributor'));

        $this->putJson('/api/v1/profile', ['country_code' => 'IN'])->assertOk();
        $this->putJson('/api/v1/profile', ['country_code' => 'GE'])
            ->assertStatus(422)->assertJsonPath('code', 'country_change_pending');

        $this->deleteJson('/api/v1/profile/country-change')->assertOk();
        $this->putJson('/api/v1/profile', ['country_code' => 'GE'])->assertOk();
    }

    public function test_approval_switches_country_and_requires_new_kyc(): void
    {
        $contributor = $this->makeUser('contributor');
        $request = CountryChangeRequest::create(['user_id' => $contributor->id, 'from_country' => 'AE', 'to_country' => 'IN']);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/staff/country-changes/{$request->id}/decision", ['decision' => 'approve'])->assertOk();

        $profile = $contributor->profile()->first();
        $this->assertSame('IN', $profile->country_code);
        $this->assertSame('unverified', $profile->kyc_status);
        $this->assertSame('IN', $profile->kyc_country_code);

        // Tasks are locked until KYC for the new country is approved.
        $this->app['auth']->forgetGuards();
        $token = $contributor->createToken('t')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/tasks')->assertStatus(403)->assertJsonPath('code', 'kyc_required');
    }

    public function test_rejection_needs_a_reason_and_changes_nothing(): void
    {
        $contributor = $this->makeUser('contributor');
        $request = CountryChangeRequest::create(['user_id' => $contributor->id, 'from_country' => 'AE', 'to_country' => 'IN']);

        Sanctum::actingAs($this->staff());
        $this->postJson("/api/v1/staff/country-changes/{$request->id}/decision", ['decision' => 'reject'])->assertStatus(422);
        $this->postJson("/api/v1/staff/country-changes/{$request->id}/decision", ['decision' => 'reject', 'note' => 'Proof missing'])->assertOk();

        $profile = $contributor->profile()->first();
        $this->assertSame('AE', $profile->country_code);
        $this->assertSame('verified', $profile->kyc_status);
        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_contributor_cannot_review_requests(): void
    {
        Sanctum::actingAs($this->makeUser('contributor'));

        $this->getJson('/api/v1/staff/country-changes')->assertStatus(403);
    }
}

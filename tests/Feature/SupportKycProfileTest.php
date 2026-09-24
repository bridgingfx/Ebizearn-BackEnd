<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Profile personal-details save, in-app support tickets (user -> staff
 * queue), and KYC submission + staff review.
 */
class SupportKycProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'contributor', array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' User',
            'email' => $role . Str::random(5) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    protected function makeStaff(array $permissions): User
    {
        $staff = $this->makeUser('moderator');
        foreach ($permissions as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $staff->directPermissions()->attach($perm->id);
        }

        return $staff;
    }

    // ------------------------------------------------------------------
    // Profile
    // ------------------------------------------------------------------

    public function test_contributor_can_save_personal_details(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $res = $this->putJson('/api/v1/profile', [
            'name' => 'Yuvaraj R',
            'phone' => '+971 50-123 4567',
            'country_code' => 'ae',
            'city' => 'Dubai',
            'bio' => 'Instagram creator.',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.user.name', 'Yuvaraj R')
            ->assertJsonPath('data.user.phone', '+971501234567')
            ->assertJsonPath('data.user.profile.country_code', 'AE')
            ->assertJsonPath('data.user.profile.city', 'Dubai')
            ->assertJsonPath('data.user.profile.bio', 'Instagram creator.');
    }

    public function test_profile_rejects_phone_without_valid_dial_code(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->putJson('/api/v1/profile', ['phone' => '+999123456'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_profile_update_requires_some_field(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->putJson('/api/v1/profile', [])->assertStatus(422);
    }

    public function test_email_cannot_be_changed_through_profile(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile', ['name' => 'New Name', 'email' => 'hijack@example.com'])->assertOk();
        $this->assertNotSame('hijack@example.com', $user->fresh()->email);
    }

    // ------------------------------------------------------------------
    // Support tickets
    // ------------------------------------------------------------------

    public function test_contributor_ticket_reaches_staff_queue_and_reply_comes_back(): void
    {
        $contributor = $this->makeUser();
        Sanctum::actingAs($contributor);

        $created = $this->postJson('/api/v1/support/tickets', [
            'subject' => 'Withdrawal still pending',
            'category' => 'payout',
            'message' => 'My withdrawal from Monday has not arrived.',
        ])->assertCreated();

        $uuid = $created->json('data.uuid');

        $staff = $this->makeStaff(['handle_disputes']);
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/staff/support/tickets')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $uuid)
            ->assertJsonPath('data.0.user.email', $contributor->email)
            ->assertJsonPath('data.0.description', 'My withdrawal from Monday has not arrived.')
            ->assertJsonPath('meta.counts.open', 1);

        $this->postJson("/api/v1/staff/support/tickets/{$uuid}/messages", ['message' => 'Checking now.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->postJson("/api/v1/staff/support/tickets/{$uuid}/messages", ['message' => 'secret', 'internal' => true])
            ->assertOk();

        $this->patchJson("/api/v1/staff/support/tickets/{$uuid}", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        // Contributor sees the staff reply but never the internal note.
        Sanctum::actingAs($contributor);
        $show = $this->getJson("/api/v1/support/tickets/{$uuid}")->assertOk();
        $messages = collect($show->json('data.messages'))->pluck('message')->all();
        $this->assertSame(['My withdrawal from Monday has not arrived.', 'Checking now.'], $messages);

        // Replying re-opens a resolved ticket.
        $this->postJson("/api/v1/support/tickets/{$uuid}/messages", ['message' => 'Still nothing.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_users_cannot_read_other_users_tickets(): void
    {
        $owner = $this->makeUser();
        $ticket = SupportTicket::create(['user_id' => $owner->id, 'subject' => 'Mine', 'category' => 'general']);

        Sanctum::actingAs($this->makeUser());
        $this->getJson("/api/v1/support/tickets/{$ticket->uuid}")->assertNotFound();
    }

    public function test_staff_queue_requires_permission(): void
    {
        Sanctum::actingAs($this->makeStaff([]));
        $this->getJson('/api/v1/staff/support/tickets')->assertForbidden();

        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/staff/support/tickets')->assertForbidden();
    }

    // ------------------------------------------------------------------
    // KYC
    // ------------------------------------------------------------------

    public function test_kyc_submission_and_staff_approval(): void
    {
        Storage::fake('local');

        $contributor = $this->makeUser();
        Sanctum::actingAs($contributor);

        $this->post('/api/v1/profile/kyc', [
            'document_type' => 'emirates_id',
            'document_front' => UploadedFile::fake()->image('front.jpg'),
            'document_back' => UploadedFile::fake()->image('back.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.user.profile.kyc_status', 'pending')
            ->assertJsonPath('data.user.profile.kyc_documents', ['front', 'back'])
            ->assertJsonMissingPath('data.user.profile.kyc_front_path');

        // A second submission while pending is refused.
        $this->post('/api/v1/profile/kyc', [
            'document_type' => 'passport',
            'document_front' => UploadedFile::fake()->image('p.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $staff = $this->makeStaff(['review_submissions']);
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/staff/kyc')
            ->assertOk()
            ->assertJsonPath('data.0.user.email', $contributor->email)
            ->assertJsonPath('meta.pending', 1);

        $this->get("/api/v1/staff/kyc/{$contributor->id}/documents/front")->assertOk();
        $this->getJson("/api/v1/staff/kyc/{$contributor->id}/documents/selfie")->assertNotFound();

        $this->postJson("/api/v1/staff/kyc/{$contributor->id}/decision", ['decision' => 'reject'])
            ->assertStatus(422); // reason required

        $this->postJson("/api/v1/staff/kyc/{$contributor->id}/decision", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.kyc_status', 'verified');

        $this->assertSame('verified', $contributor->fresh()->profile->kyc_status);
    }

    public function test_rejected_kyc_can_be_resubmitted(): void
    {
        Storage::fake('local');

        $contributor = $this->makeUser();
        Sanctum::actingAs($contributor);
        $this->post('/api/v1/profile/kyc', [
            'document_type' => 'passport',
            'document_front' => UploadedFile::fake()->image('p.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        Sanctum::actingAs($this->makeStaff(['review_submissions']));
        $this->postJson("/api/v1/staff/kyc/{$contributor->id}/decision", ['decision' => 'reject', 'reason' => 'Blurry photo'])
            ->assertOk()
            ->assertJsonPath('data.kyc_rejection_reason', 'Blurry photo');

        Sanctum::actingAs($contributor);
        $this->post('/api/v1/profile/kyc', [
            'document_type' => 'passport',
            'document_front' => UploadedFile::fake()->image('p2.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.user.profile.kyc_status', 'pending');
    }

    public function test_kyc_documents_are_staff_only(): void
    {
        $contributor = $this->makeUser();
        Sanctum::actingAs($contributor);

        $this->getJson("/api/v1/staff/kyc/{$contributor->id}/documents/front")->assertForbidden();
    }
}

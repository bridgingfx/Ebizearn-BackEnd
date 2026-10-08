<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin / Super Admin notification bell: platform activity with an unread
 * count; KYC submissions reach the review queue as pending.
 */
class StaffNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeUser(string $role): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' ' . Str::random(4),
            'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Profile::forceCreate(['user_id' => $user->id, 'country_code' => 'AE']);
        Wallet::firstOrCreate(['user_id' => $user->id], ['currency' => 'USD', 'available_balance_cents' => 0]);

        return $user;
    }

    protected function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());
    }

    public function test_platform_activity_reaches_the_admin_bell_and_can_be_marked_seen(): void
    {
        $admin = $this->makeUser('admin');
        $contributor = $this->makeUser('contributor'); // → user.registered
        SupportTicket::create(['user_id' => $contributor->id, 'subject' => 'Payout question', 'category' => 'payout']); // → support_ticket.created

        $this->actAs($admin);
        $res = $this->getJson('/api/v1/admin/notifications')->assertOk();
        $actions = collect($res->json('data'))->pluck('action');
        $this->assertContains('user.registered', $actions);
        $this->assertContains('support_ticket.created', $actions);
        $this->assertGreaterThanOrEqual(2, $res->json('meta.unread'));

        // Filter chips.
        $support = collect($this->getJson('/api/v1/admin/notifications?category=support')->json('data'))->pluck('action')->unique()->values()->all();
        $this->assertSame(['support_ticket.created'], $support);

        $this->postJson('/api/v1/admin/notifications/seen')->assertOk();
        $this->getJson('/api/v1/admin/notifications/unread-count')->assertJsonPath('data.unread', 0);

        // New activity after that is unread again.
        $this->travel(1)->seconds();
        $this->app['auth']->forgetGuards(); // a real sign-up happens signed out
        $this->makeUser('business');
        $this->actAs($admin);
        $this->getJson('/api/v1/admin/notifications/unread-count')->assertJsonPath('data.unread', 1);
    }

    public function test_only_admin_and_super_admin_have_the_bell(): void
    {
        $this->actAs($this->makeUser('moderator'));
        $this->getJson('/api/v1/admin/notifications')->assertForbidden();
        $this->actAs($this->makeUser('contributor'));
        $this->getJson('/api/v1/admin/notifications')->assertForbidden();
        $this->actAs($this->makeUser('superadmin'));
        $this->getJson('/api/v1/admin/notifications')->assertOk();
    }

    public function test_kyc_submission_reaches_review_queue_as_pending_and_can_be_decided(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $contributor = $this->makeUser('contributor');
        $this->actAs($contributor);
        $this->post('/api/v1/profile/kyc', [
            'document_type' => 'national_id',
            'document_front' => \Illuminate\Http\UploadedFile::fake()->image('front.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('pending', $contributor->profile()->first()->kyc_status);

        $this->actAs($this->makeUser('admin'));
        $this->getJson('/api/v1/staff/kyc')->assertJsonPath('meta.pending', 1);
        $this->postJson("/api/v1/staff/kyc/{$contributor->id}/decision", ['decision' => 'approve'])->assertOk();
        // Staff can still revoke an approval.
        $this->postJson("/api/v1/staff/kyc/{$contributor->id}/decision", ['decision' => 'approve'])->assertStatus(422);
        $this->postJson("/api/v1/staff/kyc/{$contributor->id}/decision", ['decision' => 'reject', 'reason' => 'Document expired'])->assertOk();
        $this->assertSame('rejected', $contributor->profile()->first()->kyc_status);
    }
}

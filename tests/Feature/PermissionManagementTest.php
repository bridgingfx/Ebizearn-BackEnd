<?php

namespace Tests\Feature;

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
 * Super Admin permission management for every role (role matrix +
 * per-user grant/deny overrides), its enforcement on contributor /
 * business / admin routes, ticket attachments, the admin user-detail
 * endpoint and audit entity names.
 */
class PermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'contributor'): User
    {
        return User::factory()->create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' Person',
            'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // Defaults + matrix
    // ------------------------------------------------------------------

    public function test_default_grants_keep_existing_behaviour(): void
    {
        $contributor = $this->makeUser('contributor');
        $business = $this->makeUser('business');
        $admin = $this->makeUser('admin');

        $this->assertTrue($contributor->hasPermission('perform_tasks'));
        $this->assertTrue($contributor->hasPermission('request_withdrawals'));
        $this->assertTrue($business->hasPermission('create_campaigns'));
        $this->assertTrue($admin->hasPermission('manage_settings'));
        $this->assertFalse($contributor->hasPermission('create_campaigns'));
    }

    public function test_superadmin_reads_and_edits_role_matrix(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));

        $res = $this->getJson('/api/v1/ops/roles')->assertOk();
        $roles = collect($res->json('data.roles'))->pluck('name')->all();
        $this->assertSame(['admin', 'moderator', 'contributor', 'business'], $roles);
        $this->assertContains('perform_tasks', collect($res->json('data.permissions'))->pluck('name')->all());

        $this->putJson('/api/v1/ops/roles/contributor/permissions', [
            'permissions' => ['perform_tasks', 'submit_kyc'],
        ])->assertOk()->assertJsonPath('data.permissions', ['perform_tasks', 'submit_kyc']);

        $this->assertFalse($this->makeUser('contributor')->hasPermission('request_withdrawals'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.permissions_updated']);
    }

    public function test_superadmin_role_is_not_editable_and_ops_is_superadmin_only(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->putJson('/api/v1/ops/roles/superadmin/permissions', ['permissions' => []])->assertStatus(422);

        Sanctum::actingAs($this->makeUser('admin'));
        $this->getJson('/api/v1/ops/roles')->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Enforcement
    // ------------------------------------------------------------------

    public function test_revoked_role_permission_blocks_contributor_route(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->putJson('/api/v1/ops/roles/contributor/permissions', [
            'permissions' => ['perform_tasks', 'request_withdrawals', 'use_referrals', 'submit_kyc'],
        ])->assertOk();

        Sanctum::actingAs($this->makeUser('contributor'));
        $this->postJson('/api/v1/support/tickets', [
            'subject' => 'Help please',
            'category' => 'general',
            'message' => 'Something is wrong here.',
        ])->assertForbidden()->assertJsonPath('code', 'permission_denied');

        // Reading own ticket history stays open.
        $this->getJson('/api/v1/support/tickets')->assertOk();
    }

    public function test_per_user_deny_overrides_role_and_grant_adds(): void
    {
        $contributor = $this->makeUser('contributor');

        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->putJson("/api/v1/ops/users/{$contributor->id}/permissions", [
            'grants' => [],
            'denies' => ['request_withdrawals'],
        ])->assertOk()
            ->assertJsonPath('data.denies', ['request_withdrawals'])
            ->assertJsonMissingPath('data.effective.request_withdrawals');

        $this->assertFalse($contributor->fresh()->hasPermission('request_withdrawals'));
        $this->assertTrue($contributor->fresh()->hasPermission('perform_tasks'));

        Sanctum::actingAs($contributor);
        $this->postJson('/api/v1/wallet/withdraw', ['amount_cents' => 5000])->assertForbidden();

        // A direct grant adds a permission the role lacks.
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->putJson("/api/v1/ops/users/{$contributor->id}/permissions", [
            'grants' => ['view_reports'],
            'denies' => [],
        ])->assertOk();
        $this->assertTrue($contributor->fresh()->hasPermission('view_reports'));
        $this->assertTrue($contributor->fresh()->hasPermission('request_withdrawals'));
    }

    public function test_superadmin_cannot_edit_self_or_another_superadmin(): void
    {
        $super = $this->makeUser('superadmin');
        Sanctum::actingAs($super);

        $this->putJson("/api/v1/ops/users/{$super->id}/permissions", ['grants' => [], 'denies' => []])->assertStatus(422);
        $other = $this->makeUser('superadmin');
        $this->putJson("/api/v1/ops/users/{$other->id}/permissions", ['grants' => [], 'denies' => []])->assertStatus(422);
    }

    public function test_admin_area_gated_by_permission(): void
    {
        $admin = $this->makeUser('admin');
        $admin->syncPermissionOverrides([], ['manage_users']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/audit-logs')->assertOk();
    }

    public function test_me_includes_effective_permissions(): void
    {
        Sanctum::actingAs($this->makeUser('contributor'));

        $perms = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.user.permissions');
        $this->assertContains('perform_tasks', $perms);
        $this->assertNotContains('manage_users', $perms);
    }

    // ------------------------------------------------------------------
    // Admin user detail + audit names
    // ------------------------------------------------------------------

    public function test_admin_user_detail_and_audit_entity_names(): void
    {
        $contributor = $this->makeUser('contributor');
        SupportTicket::create(['user_id' => $contributor->id, 'subject' => 'Payout', 'category' => 'payout']);

        $super = $this->makeUser('superadmin');
        Sanctum::actingAs($super);
        $this->putJson("/api/v1/ops/users/{$contributor->id}/permissions", ['grants' => [], 'denies' => ['use_referrals']])->assertOk();

        $this->getJson("/api/v1/admin/users/{$contributor->id}")
            ->assertOk()
            ->assertJsonPath('data.user.email', $contributor->email)
            ->assertJsonPath('data.tickets.0.subject', 'Payout')
            ->assertJsonPath('data.permissions.denies', ['use_referrals'])
            ->assertJsonPath('data.audit.0.entity_model', 'User')
            ->assertJsonPath('data.audit.0.entity_name', $contributor->name);

        $this->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.entity_model', 'User')
            ->assertJsonPath('data.0.entity_name', $contributor->name);
    }

    // ------------------------------------------------------------------
    // Ticket attachments
    // ------------------------------------------------------------------

    public function test_ticket_attachments_upload_and_download(): void
    {
        Storage::fake('local');

        $contributor = $this->makeUser('contributor');
        Sanctum::actingAs($contributor);

        $uuid = $this->post('/api/v1/support/tickets', [
            'subject' => 'Proof rejected',
            'category' => 'dispute',
            'message' => 'See my screenshot please.',
            'attachments' => [UploadedFile::fake()->image('proof.png')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.uuid');

        // Attachment-only reply (no text).
        $reply = $this->post("/api/v1/support/tickets/{$uuid}/messages", [
            'attachments' => [UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertOk();

        $messages = $reply->json('data.messages');
        $this->assertSame('proof.png', $messages[0]['attachments'][0]['name']);
        $this->assertTrue($messages[0]['attachments'][0]['is_image']);
        $this->assertSame('receipt.pdf', $messages[1]['attachments'][0]['name']);
        $this->assertArrayNotHasKey('path', $messages[0]['attachments'][0]);

        $this->get("/api/v1/support/tickets/{$uuid}/messages/{$messages[0]['id']}/attachments/0")->assertOk();

        // Empty reply is rejected.
        $this->postJson("/api/v1/support/tickets/{$uuid}/messages", [])->assertStatus(422);

        // Another user cannot fetch it; staff can.
        Sanctum::actingAs($this->makeUser('contributor'));
        $this->get("/api/v1/support/tickets/{$uuid}/messages/{$messages[0]['id']}/attachments/0", ['Accept' => 'application/json'])
            ->assertNotFound();

        Sanctum::actingAs($this->makeUser('moderator'));
        $this->get("/api/v1/staff/support/tickets/{$uuid}/messages/{$messages[1]['id']}/attachments/0")->assertOk();
    }

    public function test_invalid_utf8_from_multipart_client_cannot_poison_ticket(): void
    {
        Storage::fake('local');
        $contributor = $this->makeUser('contributor');
        Sanctum::actingAs($contributor);

        $uuid = $this->post('/api/v1/support/tickets', [
            'subject' => "Bad bytes \x97 here",
            'category' => 'general',
            'message' => "cp1252 dash \x97 sent by a non-browser client",
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.uuid');

        Sanctum::actingAs($this->makeUser('moderator'));
        $this->post("/api/v1/staff/support/tickets/{$uuid}/messages", [
            'message' => "note \x97",
            'internal' => '1',
        ], ['Accept' => 'application/json'])->assertOk();

        $this->getJson("/api/v1/staff/support/tickets/{$uuid}")->assertOk();
    }

    public function test_attachment_type_is_validated(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->makeUser('contributor'));

        $this->post('/api/v1/support/tickets', [
            'subject' => 'Bad file',
            'category' => 'bug',
            'message' => 'Executable attached.',
            'attachments' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }
}

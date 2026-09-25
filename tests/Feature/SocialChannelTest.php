<?php

namespace Tests\Feature;

use App\Models\SocialChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contributor social channels: link parsing, bio-code submission, staff
 * approval / rejection and one-owner-per-handle.
 */
class SocialChannelTest extends TestCase
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

    /**
     * @dataProvider links
     */
    public function test_profile_links_and_handles_are_normalized(string $platform, string $input, string $handle, string $url): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/v1/contributor/social-channels', ['platform' => $platform, 'profile_url' => $input])
            ->assertCreated()
            ->assertJsonPath('data.handle', $handle)
            ->assertJsonPath('data.profile_url', $url)
            ->assertJsonPath('data.status', 'unverified');
    }

    public static function links(): array
    {
        return [
            'instagram url' => ['instagram', 'https://www.instagram.com/yuvi.creates/?hl=en', 'yuvi.creates', 'https://www.instagram.com/yuvi.creates'],
            'instagram @handle' => ['instagram', '@yuvi.creates', 'yuvi.creates', 'https://www.instagram.com/yuvi.creates'],
            'tiktok url' => ['tiktok', 'tiktok.com/@yuvi_tt', 'yuvi_tt', 'https://www.tiktok.com/@yuvi_tt'],
            'youtube handle url' => ['youtube', 'https://m.youtube.com/@YuviVlogs', 'YuviVlogs', 'https://www.youtube.com/@YuviVlogs'],
            'youtube channel id' => ['youtube', 'https://www.youtube.com/channel/UC1234567890abcdef', 'UC1234567890abcdef', 'https://www.youtube.com/channel/UC1234567890abcdef'],
            'facebook numeric' => ['facebook', 'https://www.facebook.com/profile.php?id=100012345678', '100012345678', 'https://www.facebook.com/profile.php?id=100012345678'],
            'x via twitter.com' => ['x', 'https://twitter.com/yuvi_x', 'yuvi_x', 'https://x.com/yuvi_x'],
        ];
    }

    public function test_wrong_site_and_post_links_are_rejected(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/v1/contributor/social-channels', ['platform' => 'instagram', 'profile_url' => 'https://www.tiktok.com/@someone'])
            ->assertStatus(422)->assertJsonValidationErrors('profile_url');
        $this->postJson('/api/v1/contributor/social-channels', ['platform' => 'instagram', 'profile_url' => 'https://www.instagram.com/p/C8abc123/'])
            ->assertStatus(422)->assertJsonValidationErrors('profile_url');
        $this->postJson('/api/v1/contributor/social-channels', ['platform' => 'myspace', 'profile_url' => 'x'])
            ->assertStatus(422);
    }

    public function test_full_flow_submit_and_staff_approve_or_reject(): void
    {
        $contributor = $this->makeUser();
        Sanctum::actingAs($contributor);

        $channel = $this->postJson('/api/v1/contributor/social-channels', ['platform' => 'instagram', 'profile_url' => '@yuvi', 'followers' => 1200])
            ->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^EBZ-[A-Z0-9]{6}$/', $channel['verification_code']);

        $this->postJson("/api/v1/contributor/social-channels/{$channel['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/v1/contributor/social-channels/{$channel['id']}/submit")->assertStatus(422);

        // Contributors cannot review.
        $this->getJson('/api/v1/staff/social-channels')->assertForbidden();

        Sanctum::actingAs($this->makeUser('admin'));
        $this->getJson('/api/v1/staff/social-channels')->assertOk()->assertJsonPath('meta.pending', 1)->assertJsonPath('data.0.user.id', $contributor->id);

        $this->postJson("/api/v1/staff/social-channels/{$channel['id']}/decision", ['decision' => 'reject'])->assertStatus(422);
        $this->postJson("/api/v1/staff/social-channels/{$channel['id']}/decision", ['decision' => 'reject', 'reason' => 'Code not found in bio.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        // Resubmit after fixing the bio, then approve with a corrected follower count.
        Sanctum::actingAs($contributor);
        $this->postJson("/api/v1/contributor/social-channels/{$channel['id']}/submit")->assertOk();

        Sanctum::actingAs($this->makeUser('moderator'));
        $this->postJson("/api/v1/staff/social-channels/{$channel['id']}/decision", ['decision' => 'approve', 'followers' => 1150])
            ->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.followers', 1150);

        // Updating only the follower count keeps it verified; a new handle resets it.
        Sanctum::actingAs($contributor);
        $this->postJson('/api/v1/contributor/social-channels', ['platform' => 'instagram', 'profile_url' => 'instagram.com/yuvi', 'followers' => 1300])
            ->assertCreated()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.verification_code', $channel['verification_code']);
        $this->postJson('/api/v1/contributor/social-channels', ['platform' => 'instagram', 'profile_url' => '@yuvi.new'])
            ->assertCreated()->assertJsonPath('data.status', 'unverified');
        $this->assertSame(1, SocialChannel::where('user_id', $contributor->id)->count());
    }

    public function test_a_handle_cannot_be_claimed_by_two_accounts(): void
    {
        $owner = $this->makeUser();
        SocialChannel::create(['user_id' => $owner->id, 'platform' => 'tiktok', 'handle' => 'Star_Creator', 'profile_url' => 'https://www.tiktok.com/@Star_Creator', 'verification_code' => 'EBZ-AAAAAA', 'status' => 'verified']);

        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/contributor/social-channels', ['platform' => 'tiktok', 'profile_url' => '@star_creator'])
            ->assertStatus(422)->assertJsonFragment(['success' => false]);
    }

    public function test_contributor_can_only_touch_own_channels(): void
    {
        $other = $this->makeUser();
        $channel = SocialChannel::create(['user_id' => $other->id, 'platform' => 'x', 'handle' => 'someone', 'profile_url' => 'https://x.com/someone', 'verification_code' => 'EBZ-BBBBBB']);

        Sanctum::actingAs($this->makeUser());
        $this->deleteJson("/api/v1/contributor/social-channels/{$channel->id}")->assertNotFound();
        $this->postJson("/api/v1/contributor/social-channels/{$channel->id}/submit")->assertNotFound();
        $this->getJson('/api/v1/contributor/social-channels')->assertOk()->assertJsonCount(0, 'data');
    }
}

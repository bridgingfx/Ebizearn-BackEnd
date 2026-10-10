<?php

namespace App\Services\Social;

use App\Models\SocialChannel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Our robo" — the automated checker for OAuth-connected social channels.
 *
 * The OAuth handshake itself proves the contributor owns the account, so a
 * freshly connected channel is verified on the spot. After that the robo
 * re-checks every channel daily: it refreshes expiring tokens, re-reads the
 * profile through the platform's official API, and syncs the username and
 * follower count. If a token dies repeatedly the channel drops back to
 * unverified with a note telling the contributor to reconnect.
 *
 * Manual (bio-code) channels are untouched — staff review those, as before.
 */
class SocialRoboVerifier
{
    public function __construct(
        private SocialOAuthService $oauth,
        private SocialConnectSettings $settings,
    ) {
    }

    /**
     * Verify a channel right after its OAuth handshake.
     * @return array{username:string, followers:?int}
     */
    public function verifyNow(SocialChannel $channel): array
    {
        $profile = $this->oauth->fetchProfile($channel->platform, $this->token($channel));

        $channel->update([
            'oauth_username' => $profile['username'],
            'handle' => $profile['username'] !== '' ? $profile['username'] : $channel->handle,
            'profile_url' => $profile['profile_url'] ?? $channel->profile_url,
            'followers' => $profile['followers'] ?? $channel->followers,
            'status' => 'verified',
            'verified_at' => now(),
            'last_robo_check_at' => now(),
            'robo_failures' => 0,
            'robo_check_note' => 'Verified automatically via ' . $this->settings::labels()[$channel->platform] . ' login.',
        ]);

        return ['username' => $profile['username'], 'followers' => $profile['followers']];
    }

    /** Daily re-check of one OAuth channel. Never throws. */
    public function recheck(SocialChannel $channel): void
    {
        try {
            $accessToken = $this->token($channel);

            // Refresh tokens that expire within the hour (where refresh is supported).
            // Instagram has no separate refresh token: its long-lived token refreshes
            // itself, so renew it a week ahead. Others renew within the hour.
            $isInstagram = $channel->platform === 'instagram';
            $window = $isInstagram ? now()->addDays(7) : now()->addHour();
            if ($channel->oauth_expires_at && $channel->oauth_expires_at->lt($window) && ($channel->oauth_refresh_token || $isInstagram)) {
                $fresh = $this->oauth->refreshToken($channel->platform, $isInstagram ? $accessToken : Crypt::decryptString($channel->oauth_refresh_token));
                if ($fresh) {
                    $channel->oauth_access_token = Crypt::encryptString($fresh['access_token']);
                    if (!empty($fresh['refresh_token'])) {
                        $channel->oauth_refresh_token = Crypt::encryptString($fresh['refresh_token']);
                    }
                    $channel->oauth_expires_at = $fresh['expires_in'] ? now()->addSeconds($fresh['expires_in']) : null;
                    $accessToken = $fresh['access_token'];
                }
            }

            $profile = $this->oauth->fetchProfile($channel->platform, $accessToken);

            $channel->update([
                'oauth_username' => $profile['username'],
                'followers' => $profile['followers'] ?? $channel->followers,
                'last_robo_check_at' => now(),
                'robo_failures' => 0,
                'robo_check_note' => $channel->status === 'verified' ? $channel->robo_check_note : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Social robo check failed', ['channel' => $channel->id, 'error' => $e->getMessage()]);

            $failures = $channel->robo_failures + 1;
            $update = [
                'last_robo_check_at' => now(),
                'robo_failures' => $failures,
                'robo_check_note' => 'Automatic check failed — ' . Str::limit($e->getMessage(), 120),
            ];
            if ($failures >= 3) {
                $update['status'] = 'unverified';
                $update['robo_check_note'] = 'Connection expired — please reconnect your account.';
            }
            $channel->update($update);
        }
    }

    private function token(SocialChannel $channel): string
    {
        return Crypt::decryptString($channel->oauth_access_token);
    }
}

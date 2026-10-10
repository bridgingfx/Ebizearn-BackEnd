<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Connect with …" OAuth drivers for contributor social channels.
 *
 * The contributor logs in on the platform's own site; the platform sends us
 * back a code, we swap it for an access token, and the token lets our robo
 * confirm the account really belongs to them (username, follower count).
 *
 * We NEVER ask for, receive, or store a contributor's platform password —
 * OAuth exists precisely so passwords never leave the platform.
 */
class SocialOAuthService
{
    public const INSTAGRAM_SCOPES = 'instagram_business_basic';

    public function __construct(private SocialConnectSettings $settings)
    {
    }

    public function redirectUri(string $platform): string
    {
        return rtrim((string) config('app.url'), '/') . "/oauth/social/{$platform}/callback";
    }

    /** Signed, tamper-proof state binding the handshake to one user for 10 minutes. */
    public function makeState(int $userId, string $platform, ?string $verifier = null): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $userId,
            'platform' => $platform,
            'verifier' => $verifier,
            'exp' => now()->addMinutes(10)->timestamp,
        ]));
    }

    /** @return array{user_id:int, platform:string, verifier:?string} */
    public function readState(string $platform, string $state): array
    {
        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable) {
            abort(400, 'This login link has expired. Please try connecting again.');
        }
        if (!is_array($data) || ($data['platform'] ?? null) !== $platform || ($data['exp'] ?? 0) < now()->timestamp) {
            abort(400, 'This login link has expired. Please try connecting again.');
        }

        return ['user_id' => (int) $data['user_id'], 'platform' => $platform, 'verifier' => $data['verifier'] ?? null];
    }

    public function authorizationUrl(string $platform, int $userId): string
    {
        $clientId = $this->settings->clientId($platform);
        $redirect = $this->redirectUri($platform);

        return match ($platform) {
            'tiktok' => $this->tiktokAuthorize($clientId, $redirect, $this->makeState($userId, $platform)),
            'x' => $this->xAuthorize($clientId, $redirect, $userId),
            'facebook' => 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query([
                'client_id' => $clientId, 'redirect_uri' => $redirect,
                'state' => $this->makeState($userId, $platform), 'scope' => 'public_profile',
            ]),
            // Instagram API with Instagram Login (professional accounts). Read-only:
            // instagram_business_basic = profile + the account's own posts.
            'instagram' => 'https://www.instagram.com/oauth/authorize?' . http_build_query([
                'enable_fb_login' => 0, 'force_reauth' => 'true',
                'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code',
                'scope' => self::INSTAGRAM_SCOPES, 'state' => $this->makeState($userId, $platform),
            ]),
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code',
                'scope' => 'https://www.googleapis.com/auth/youtube.readonly',
                'access_type' => 'offline', 'prompt' => 'consent',
                'state' => $this->makeState($userId, $platform),
            ]),
            default => abort(422, 'Unknown platform.'),
        };
    }

    // ------------------------------------------------------------------
    // X needs PKCE: the verifier travels inside the encrypted state.
    // ------------------------------------------------------------------
    private function xAuthorize(string $clientId, string $redirect, int $userId): string
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return 'https://twitter.com/i/oauth2/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect,
            'scope' => 'users.read tweet.read offline.access',
            'state' => $this->makeState($userId, 'x', $verifier),
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]);
    }

    private function tiktokAuthorize(string $clientId, string $redirect, string $state): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query([
            'client_key' => $clientId, 'scope' => 'user.info.basic,user.info.profile',
            'response_type' => 'code', 'redirect_uri' => $redirect, 'state' => $state,
        ]);
    }

    /**
     * Swap the code for tokens.
     * @return array{access_token:string, refresh_token:?string, expires_in:?int}
     */
    public function exchangeCode(string $platform, string $code, ?string $verifier = null): array
    {
        $redirect = $this->redirectUri($platform);
        $id = $this->settings->clientId($platform);
        $secret = $this->settings->secretFor($platform);

        $tokens = match ($platform) {
            'tiktok' => $this->asJson(Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
                'client_key' => $id, 'client_secret' => $secret, 'code' => $code,
                'grant_type' => 'authorization_code', 'redirect_uri' => $redirect,
            ])),
            'x' => $this->asJson(Http::withBasicAuth($id, $secret)->asForm()->post('https://api.twitter.com/2/oauth2/token', [
                'code' => $code, 'grant_type' => 'authorization_code',
                'redirect_uri' => $redirect, 'code_verifier' => $verifier,
            ])),
            'facebook' => $this->asJson(Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
                'client_id' => $id, 'redirect_uri' => $redirect,
                'client_secret' => $secret, 'code' => $code,
            ])),
            'google' => $this->asJson(Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $id, 'client_secret' => $secret, 'code' => $code,
                'grant_type' => 'authorization_code', 'redirect_uri' => $redirect,
            ])),
            'instagram' => $this->instagramShortToken($id, $secret, $code, $redirect),
            default => abort(422, 'Unknown platform.'),
        };

        if (empty($tokens['access_token'])) {
            abort(422, 'The platform did not return an access token. Please try again.');
        }

        // Facebook: trade the short-lived token for a 60-day one.
        if ($platform === 'facebook') {
            $long = $this->asJson(Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
                'grant_type' => 'fb_exchange_token', 'client_id' => $id,
                'client_secret' => $secret, 'fb_exchange_token' => $tokens['access_token'],
            ]));
            if (!empty($long['access_token'])) {
                $tokens = $long;
            }
        }

        // Instagram: trade the 1-hour token for a 60-day one (refreshable).
        if ($platform === 'instagram') {
            $long = $this->asJson(Http::get('https://graph.instagram.com/access_token', [
                'grant_type' => 'ig_exchange_token', 'client_secret' => $secret, 'access_token' => $tokens['access_token'],
            ]));
            if (!empty($long['access_token'])) {
                $tokens = $long + ['scopes' => $tokens['scopes'] ?? null];
            }
        }

        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_in' => isset($tokens['expires_in']) ? (int) $tokens['expires_in'] : null,
            'scopes' => $tokens['scopes'] ?? null,
        ];
    }

    /** Instagram's token endpoint answers either flat or as {data: [ … ]}. */
    private function instagramShortToken(string $id, string $secret, string $code, string $redirect): array
    {
        $json = $this->asJson(Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
            'client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect, 'code' => $code,
        ]));
        $row = $json['data'][0] ?? $json;
        $permissions = $row['permissions'] ?? null;

        return [
            'access_token' => $row['access_token'] ?? null,
            'scopes' => is_array($permissions) ? implode(',', $permissions) : ($permissions ?: self::INSTAGRAM_SCOPES),
        ];
    }

    /**
     * Refresh a token where the platform supports it.
     * @return array{access_token:string, refresh_token:?string, expires_in:?int}|null
     */
    public function refreshToken(string $platform, string $refreshToken): ?array
    {
        $id = $this->settings->clientId($platform);
        $secret = $this->settings->secretFor($platform);

        $tokens = match ($platform) {
            'tiktok' => $this->asJson(Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
                'client_key' => $id, 'client_secret' => $secret,
                'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken,
            ])),
            'x' => $this->asJson(Http::withBasicAuth($id, $secret)->asForm()->post('https://api.twitter.com/2/oauth2/token', [
                'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken,
            ])),
            'google' => $this->asJson(Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $id, 'client_secret' => $secret,
                'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken,
            ])),
            // Instagram: the long-lived token itself is the refresh credential.
            'instagram' => $this->asJson(Http::get('https://graph.instagram.com/refresh_access_token', [
                'grant_type' => 'ig_refresh_token', 'access_token' => $refreshToken,
            ])),
            default => null, // facebook long-lived tokens are not refreshable
        };

        if (empty($tokens) || empty($tokens['access_token'])) {
            return null;
        }

        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $refreshToken,
            'expires_in' => isset($tokens['expires_in']) ? (int) $tokens['expires_in'] : null,
        ];
    }

    /**
     * Who just logged in? Uses the platform's official API only.
     * @return array{provider_user_id:string, username:string, followers:?int, profile_url:?string}
     */
    public function fetchProfile(string $platform, string $accessToken): array
    {
        return match ($platform) {
            'tiktok' => $this->tiktokProfile($accessToken),
            'x' => $this->xProfile($accessToken),
            'facebook' => $this->facebookProfile($accessToken),
            'google' => $this->youtubeProfile($accessToken),
            'instagram' => $this->instagramProfile($accessToken),
            default => abort(422, 'Unknown platform.'),
        };
    }

    private function instagramProfile(string $token): array
    {
        $u = $this->asJson(Http::get('https://graph.instagram.com/' . config('verification.instagram.graph_version', 'v21.0') . '/me', [
            'fields' => 'user_id,username,account_type,followers_count', 'access_token' => $token,
        ]));
        abort_unless(!empty($u['user_id'] ?? $u['id'] ?? null), 422, 'Instagram did not return a profile. Use an Instagram professional (Business or Creator) account.');

        return [
            'provider_user_id' => (string) ($u['user_id'] ?? $u['id']),
            'username' => (string) ($u['username'] ?? ''),
            'followers' => isset($u['followers_count']) ? (int) $u['followers_count'] : null,
            'profile_url' => !empty($u['username']) ? 'https://www.instagram.com/' . $u['username'] . '/' : null,
        ];
    }

    private function tiktokProfile(string $token): array
    {
        $u = $this->asJson(Http::withToken($token)->get(
            'https://open.tiktokapis.com/v2/user/info/',
            ['fields' => 'open_id,display_name,username,follower_count,bio_description']
        ))['data']['user'] ?? null;
        abort_unless($u && !empty($u['open_id']), 422, 'TikTok did not return a profile. Please try again.');

        return [
            'provider_user_id' => (string) $u['open_id'],
            'username' => (string) ($u['username'] ?? $u['display_name'] ?? ''),
            'followers' => isset($u['follower_count']) ? (int) $u['follower_count'] : null,
            'profile_url' => !empty($u['username']) ? 'https://www.tiktok.com/@' . $u['username'] : null,
        ];
    }

    private function xProfile(string $token): array
    {
        $u = $this->asJson(Http::withToken($token)->get(
            'https://api.twitter.com/2/users/me',
            ['user.fields' => 'username,name,description,public_metrics,profile_image_url']
        ))['data'] ?? null;
        abort_unless($u && !empty($u['id']), 422, 'X did not return a profile. Please try again.');

        return [
            'provider_user_id' => (string) $u['id'],
            'username' => (string) ($u['username'] ?? ''),
            'followers' => isset($u['public_metrics']['followers_count']) ? (int) $u['public_metrics']['followers_count'] : null,
            'profile_url' => !empty($u['username']) ? 'https://x.com/' . $u['username'] : null,
        ];
    }

    private function facebookProfile(string $token): array
    {
        $u = $this->asJson(Http::get('https://graph.facebook.com/v19.0/me', [
            'fields' => 'id,name', 'access_token' => $token,
        ]));
        abort_unless(!empty($u['id']), 422, 'Facebook did not return a profile. Please try again.');

        return [
            'provider_user_id' => (string) $u['id'],
            'username' => (string) ($u['name'] ?? ''),
            'followers' => null, // personal profiles do not expose this via the API
            'profile_url' => 'https://www.facebook.com/profile.php?id=' . $u['id'],
        ];
    }

    private function youtubeProfile(string $token): array
    {
        $item = $this->asJson(Http::withToken($token)->get(
            'https://www.googleapis.com/youtube/v3/channels',
            ['part' => 'snippet,statistics', 'mine' => 'true']
        ))['items'][0] ?? null;
        abort_unless($item && !empty($item['id']), 422, 'YouTube did not return a channel. Please try again.');

        $handle = $item['snippet']['customUrl'] ?? $item['snippet']['title'] ?? $item['id'];

        return [
            'provider_user_id' => (string) $item['id'],
            'username' => ltrim((string) $handle, '@'),
            'followers' => isset($item['statistics']['subscriberCount']) ? (int) $item['statistics']['subscriberCount'] : null,
            'profile_url' => 'https://www.youtube.com/channel/' . $item['id'],
        ];
    }

    private function asJson($response): array
    {
        $data = $response->json();
        if (!is_array($data)) {
            abort(422, 'The platform returned an unexpected response. Please try again.');
        }

        return $data;
    }
}

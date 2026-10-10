<?php

namespace App\Services\Social;

use App\Models\SocialChannel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a contributor's own Instagram posts through the official
 * "Instagram API with Instagram Login" (graph.instagram.com), using the
 * token they granted via OAuth (scope instagram_business_basic). Works for
 * Instagram professional accounts (Business / Creator) only — that is a
 * Meta rule, not ours.
 *
 * Every call returns an outcome instead of throwing, so callers can tell
 * "the post is gone" (definite) from "we couldn't check" (retry later):
 *   ok | not_found | token_invalid | not_connected | unavailable
 */
class InstagramGraphClient
{
    public const MEDIA_FIELDS = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,username';

    public function __construct(private SocialOAuthService $oauth)
    {
    }

    private function base(): string
    {
        return 'https://graph.instagram.com/' . config('verification.instagram.graph_version', 'v21.0');
    }

    /** The contributor's connected Instagram channel (OAuth), or null. */
    public function channelFor(int $userId): ?SocialChannel
    {
        return SocialChannel::where('user_id', $userId)
            ->where('platform', 'instagram')
            ->where('connected_via', 'oauth')
            ->whereNotNull('oauth_access_token')
            ->first();
    }

    /**
     * Find the post a proof link points to among the account's own media.
     * @return array{outcome: string, post: ?array, error: ?string}
     */
    public function findByPermalink(SocialChannel $channel, string $url): array
    {
        $code = self::shortcode($url);
        if (!$code) {
            return ['outcome' => 'not_found', 'post' => null, 'error' => 'Not an Instagram post / reel link.'];
        }

        $token = $this->token($channel);
        if (!$token) {
            return ['outcome' => 'token_invalid', 'post' => null, 'error' => 'Instagram token missing or expired.'];
        }

        $next = $this->base() . '/me/media';
        $params = ['fields' => self::MEDIA_FIELDS, 'limit' => 50, 'access_token' => $token];
        // Up to 4 pages (200 most recent posts).
        for ($page = 0; $page < 4 && $next; $page++) {
            $res = $this->get($next, $params);
            if ($res['outcome'] !== 'ok') {
                return ['outcome' => $res['outcome'], 'post' => null, 'error' => $res['error']];
            }
            foreach ($res['data']['data'] ?? [] as $media) {
                if (self::shortcode((string) ($media['permalink'] ?? '')) === $code) {
                    return ['outcome' => 'ok', 'post' => $media, 'error' => null];
                }
            }
            $next = $res['data']['paging']['next'] ?? null;
            $params = []; // the "next" URL already carries every parameter
        }

        return ['outcome' => 'not_found', 'post' => null, 'error' => 'This post is not among the connected account\'s posts.'];
    }

    /**
     * Read one post by its media id (final check).
     * @return array{outcome: string, post: ?array, error: ?string}
     */
    public function media(SocialChannel $channel, string $mediaId): array
    {
        $token = $this->token($channel);
        if (!$token) {
            return ['outcome' => 'token_invalid', 'post' => null, 'error' => 'Instagram token missing or expired.'];
        }

        $res = $this->get($this->base() . '/' . rawurlencode($mediaId), ['fields' => self::MEDIA_FIELDS, 'access_token' => $token]);

        return ['outcome' => $res['outcome'], 'post' => $res['outcome'] === 'ok' ? $res['data'] : null, 'error' => $res['error']];
    }

    /** "ABC123" from instagram.com/p/ABC123/, /reel/ABC123, /reels/…, /tv/… */
    public static function shortcode(string $url): ?string
    {
        return preg_match('#instagram\.com/(?:[\w.]+/)?(?:p|reel|reels|tv)/([A-Za-z0-9_-]+)#i', $url, $m) ? $m[1] : null;
    }

    /** Refreshes a long-lived token that expires within 7 days (Instagram allows it after 24h). */
    private function token(SocialChannel $channel): ?string
    {
        try {
            $token = Crypt::decryptString((string) $channel->oauth_access_token);
        } catch (\Throwable) {
            return null;
        }

        if ($channel->oauth_expires_at && $channel->oauth_expires_at->isPast()) {
            return null;
        }

        if ($channel->oauth_expires_at && $channel->oauth_expires_at->lt(now()->addDays(7))) {
            $fresh = $this->oauth->refreshToken('instagram', $token);
            if ($fresh) {
                $channel->forceFill([
                    'oauth_access_token' => Crypt::encryptString($fresh['access_token']),
                    'oauth_expires_at' => $fresh['expires_in'] ? now()->addSeconds($fresh['expires_in']) : $channel->oauth_expires_at,
                ])->save();
                $token = $fresh['access_token'];
            }
        }

        return $token;
    }

    /** @return array{outcome: string, data: ?array, error: ?string} */
    private function get(string $url, array $params): array
    {
        try {
            $res = Http::timeout(20)->acceptJson()->get($url, $params);
        } catch (\Throwable $e) {
            Log::warning('Instagram API unreachable', ['error' => $e->getMessage()]);

            return ['outcome' => 'unavailable', 'data' => null, 'error' => 'Instagram API unreachable.'];
        }

        return $this->classify($res);
    }

    /**
     * Meta error codes: 190 = token invalid / revoked; 10 / 200 = permission
     * missing; 100 (or HTTP 404) on a media id = it doesn't exist any more;
     * 4 / 17 / 32 / 613 = rate limits; 1 / 2 = temporary.
     */
    private function classify(Response $res): array
    {
        if ($res->successful()) {
            return ['outcome' => 'ok', 'data' => $res->json() ?? [], 'error' => null];
        }

        $err = $res->json('error') ?? [];
        $code = (int) ($err['code'] ?? 0);
        $message = mb_substr((string) ($err['message'] ?? 'HTTP ' . $res->status()), 0, 300);

        // Code 100 is also used for permission problems, so only treat it as
        // "deleted" when Meta says the object does not exist (subcode 33).
        $missing = $res->status() === 404
            || ($code === 100 && ((int) ($err['error_subcode'] ?? 0) === 33 || stripos($message, 'does not exist') !== false));

        $outcome = match (true) {
            $code === 190, in_array($code, [10, 200], true) => 'token_invalid',
            $missing => 'not_found',
            default => 'unavailable',
        };

        // Never log tokens — only Meta's error code and message.
        Log::info('Instagram API error', ['status' => $res->status(), 'code' => $code, 'outcome' => $outcome]);

        return ['outcome' => $outcome, 'data' => null, 'error' => $message];
    }
}

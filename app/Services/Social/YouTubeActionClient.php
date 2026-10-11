<?php

namespace App\Services\Social;

use App\Models\SocialChannel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Confirms a YouTube "subscribe" task through the official YouTube Data API,
 * with the token the contributor granted (scope youtube.readonly):
 * subscriptions.list(mine=true, forChannelId=<target>) is non-empty only if
 * the contributor's own channel is subscribed to the target channel.
 *
 * Outcomes: ok (subscribed) | not_found (not subscribed) | token_invalid |
 * not_connected | unavailable — the same vocabulary as InstagramGraphClient.
 */
class YouTubeActionClient
{
    private const API = 'https://www.googleapis.com/youtube/v3';

    public function __construct(private SocialOAuthService $oauth)
    {
    }

    public function channelFor(int $userId): ?SocialChannel
    {
        return SocialChannel::where('user_id', $userId)->where('platform', 'youtube')
            ->where('connected_via', 'oauth')->whereNotNull('oauth_access_token')->first();
    }

    /**
     * @return array{outcome: string, target_channel_id: ?string, subscribed_at: ?string, error: ?string}
     */
    public function checkSubscribed(SocialChannel $channel, string $targetUrl): array
    {
        $token = $this->token($channel);
        if (!$token) {
            return ['outcome' => 'token_invalid', 'target_channel_id' => null, 'subscribed_at' => null, 'error' => 'YouTube connection expired.'];
        }

        $target = $this->resolveChannelId($targetUrl, $token);
        if ($target['outcome'] !== 'ok') {
            return ['outcome' => $target['outcome'], 'target_channel_id' => null, 'subscribed_at' => null, 'error' => $target['error']];
        }

        $res = $this->get('/subscriptions', ['part' => 'snippet', 'mine' => 'true', 'forChannelId' => $target['id'], 'maxResults' => 1], $token);
        if ($res['outcome'] !== 'ok') {
            return ['outcome' => $res['outcome'], 'target_channel_id' => $target['id'], 'subscribed_at' => null, 'error' => $res['error']];
        }

        $item = $res['data']['items'][0] ?? null;

        return [
            'outcome' => $item ? 'ok' : 'not_found',
            'target_channel_id' => $target['id'],
            'subscribed_at' => $item['snippet']['publishedAt'] ?? null,
            'error' => $item ? null : 'The connected YouTube channel is not subscribed to the target channel.',
        ];
    }

    /** UC… id from youtube.com/channel/UC…, or look up youtube.com/@handle. */
    private function resolveChannelId(string $url, string $token): array
    {
        if (preg_match('#youtube\.com/channel/(UC[\w-]{20,})#i', $url, $m)) {
            return ['outcome' => 'ok', 'id' => $m[1], 'error' => null];
        }
        if (preg_match('#youtube\.com/@([\w.-]+)#i', $url, $m)) {
            $res = $this->get('/channels', ['part' => 'id', 'forHandle' => '@' . $m[1]], $token);
            if ($res['outcome'] !== 'ok') {
                return ['outcome' => $res['outcome'], 'id' => null, 'error' => $res['error']];
            }
            $id = $res['data']['items'][0]['id'] ?? null;

            return $id ? ['outcome' => 'ok', 'id' => $id, 'error' => null] : ['outcome' => 'unavailable', 'id' => null, 'error' => 'Target YouTube channel not found.'];
        }

        return ['outcome' => 'unavailable', 'id' => null, 'error' => 'The task has no YouTube channel link to check against.'];
    }

    private function token(SocialChannel $channel): ?string
    {
        try {
            $token = Crypt::decryptString((string) $channel->oauth_access_token);
        } catch (\Throwable) {
            return null;
        }
        if ($channel->oauth_expires_at && $channel->oauth_expires_at->lt(now()->addMinutes(5))) {
            if (!$channel->oauth_refresh_token) {
                return null;
            }
            try {
                $fresh = $this->oauth->refreshToken('google', Crypt::decryptString($channel->oauth_refresh_token));
            } catch (\Throwable) {
                $fresh = null;
            }
            if (!$fresh) {
                return null;
            }
            $channel->forceFill([
                'oauth_access_token' => Crypt::encryptString($fresh['access_token']),
                'oauth_expires_at' => $fresh['expires_in'] ? now()->addSeconds($fresh['expires_in']) : null,
            ])->save();
            $token = $fresh['access_token'];
        }

        return $token;
    }

    private function get(string $path, array $params, string $token): array
    {
        try {
            $res = Http::withToken($token)->timeout(20)->acceptJson()->get(self::API . $path, $params);
        } catch (\Throwable $e) {
            return ['outcome' => 'unavailable', 'data' => null, 'error' => 'YouTube API unreachable.'];
        }
        if ($res->successful()) {
            return ['outcome' => 'ok', 'data' => $res->json() ?? [], 'error' => null];
        }
        $status = $res->status();
        Log::info('YouTube API error', ['status' => $status]);

        // 401 / missing permission = reconnect; 403 quota / rate limits = try later.
        $reason = (string) $res->json('error.errors.0.reason');
        $tokenProblem = $status === 401 || ($status === 403 && in_array($reason, ['insufficientPermissions', 'forbidden', 'authError'], true));

        return [
            'outcome' => $tokenProblem ? 'token_invalid' : 'unavailable',
            'data' => null,
            'error' => mb_substr((string) ($res->json('error.message') ?? 'HTTP ' . $status), 0, 300),
        ];
    }
}

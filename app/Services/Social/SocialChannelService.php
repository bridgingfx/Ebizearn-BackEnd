<?php

namespace App\Services\Social;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns what a contributor pastes (a profile link or an @handle) into a
 * canonical handle + profile URL for the chosen platform, rejecting links
 * that point at another site or at a post instead of a profile.
 */
class SocialChannelService
{
    /** Allowed hosts and the canonical profile URL pattern per platform. */
    private const PLATFORMS = [
        'instagram' => ['hosts' => ['instagram.com'], 'url' => 'https://www.instagram.com/%s'],
        'tiktok' => ['hosts' => ['tiktok.com'], 'url' => 'https://www.tiktok.com/@%s'],
        'youtube' => ['hosts' => ['youtube.com'], 'url' => 'https://www.youtube.com/@%s'],
        'facebook' => ['hosts' => ['facebook.com', 'fb.com'], 'url' => 'https://www.facebook.com/%s'],
        'x' => ['hosts' => ['x.com', 'twitter.com'], 'url' => 'https://x.com/%s'],
    ];

    /** First path segments that are pages, not profiles. */
    private const RESERVED = ['p', 'reel', 'reels', 'stories', 'explore', 'watch', 'shorts', 'video', 'status', 'hashtag',
        'search', 'home', 'login', 'share', 'groups', 'events', 'i', 'intent', 'tag', 'music', 'embed', 'results', 'playlist'];

    /**
     * @return array{handle: string, profile_url: string}
     */
    public function normalize(string $platform, string $input): array
    {
        $input = trim($input);
        $conf = self::PLATFORMS[$platform];

        // Bare handle: "@name" or "name".
        if (!Str::contains($input, ['/', '.com', '.be'])) {
            return $this->fromHandle($platform, $input);
        }

        $url = Str::startsWith($input, ['http://', 'https://']) ? $input : 'https://' . $input;
        $parts = parse_url($url);
        $host = strtolower(preg_replace('/^(www\.|m\.|mobile\.|web\.)/', '', $parts['host'] ?? ''));

        $hostOk = collect($conf['hosts'])->contains(fn ($h) => $host === $h || str_ends_with($host, '.' . $h));
        if (!$hostOk) {
            $this->fail('This is not ' . ($platform === 'instagram' ? 'an ' : 'a ') . $this->label($platform) . ' link. Paste your ' . $this->label($platform) . ' profile link.');
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? '')));

        // Facebook numeric profiles: /profile.php?id=123
        if ($platform === 'facebook' && ($segments[0] ?? '') === 'profile.php') {
            parse_str($parts['query'] ?? '', $query);
            $id = preg_replace('/\D/', '', (string) ($query['id'] ?? ''));
            if ($id === '') {
                $this->fail('Paste the full Facebook profile link.');
            }
            return ['handle' => $id, 'profile_url' => 'https://www.facebook.com/profile.php?id=' . $id];
        }

        // YouTube channel IDs / legacy custom URLs: /channel/UC…, /c/name, /user/name
        if ($platform === 'youtube' && in_array($segments[0] ?? '', ['channel', 'c', 'user'], true) && !empty($segments[1])) {
            $name = $segments[1];
            $this->assertHandle($name, 'youtube');
            return ['handle' => $name, 'profile_url' => 'https://www.youtube.com/' . $segments[0] . '/' . $name];
        }

        $first = $segments[0] ?? '';
        if ($first === '' || in_array(strtolower(ltrim($first, '@')), self::RESERVED, true)) {
            $this->fail('Paste your profile link, not a link to a post or video.');
        }

        return $this->fromHandle($platform, $first);
    }

    public function label(string $platform): string
    {
        return ['instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'facebook' => 'Facebook', 'x' => 'X (Twitter)'][$platform] ?? $platform;
    }

    /**
     * A short code the contributor puts in their bio, e.g. EBZ-7K2P9Q.
     */
    public function newCode(): string
    {
        // No look-alike characters (0/O, 1/I/L) so the code is easy to type into a bio.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        return 'EBZ-' . collect(range(1, 6))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
    }

    /**
     * @return array{handle: string, profile_url: string}
     */
    private function fromHandle(string $platform, string $raw): array
    {
        $handle = ltrim(trim($raw), '@');
        $this->assertHandle($handle, $platform);

        return ['handle' => $handle, 'profile_url' => sprintf(self::PLATFORMS[$platform]['url'], $handle)];
    }

    private function assertHandle(string $handle, string $platform): void
    {
        if (!preg_match('/^[A-Za-z0-9._\-]{2,100}$/', $handle)) {
            $this->fail('Enter a valid ' . $this->label($platform) . ' username or profile link.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['profile_url' => $message]);
    }
}

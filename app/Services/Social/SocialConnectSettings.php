<?php

namespace App\Services\Social;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * "Connect with …" OAuth apps for contributor social channels, managed by
 * Super Admin in Admin → Settings → Social connect.
 *
 * Platforms: tiktok, x, facebook, google (YouTube channel), instagram
 * (Instagram API with Instagram Login — professional accounts only; personal
 * accounts keep the manual bio-code flow).
 *
 * Client secrets are encrypted with the app key before they are stored — the
 * settings table never holds a usable secret in plain text. We never ask for
 * or store a contributor's platform password, ever.
 */
class SocialConnectSettings
{
    public const PLATFORMS = ['tiktok', 'x', 'facebook', 'google', 'instagram'];

    public static function labels(): array
    {
        return ['tiktok' => 'TikTok', 'x' => 'X (Twitter)', 'facebook' => 'Facebook', 'google' => 'YouTube (Google)', 'instagram' => 'Instagram (Professional)'];
    }

    public function clientId(string $platform): string
    {
        return trim((string) SystemSetting::get("social_connect_{$platform}_client_id", ''));
    }

    public function secretFor(string $platform): string
    {
        $raw = (string) SystemSetting::get("social_connect_{$platform}_client_secret", '');
        if ($raw === '') {
            return '';
        }
        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return '';
        }
    }

    public function hasSecret(string $platform): bool
    {
        return $this->secretFor($platform) !== '';
    }

    /** Enabled = switched on AND both ID and secret are present. */
    public function enabled(string $platform): bool
    {
        $saved = SystemSetting::get("social_connect_{$platform}_enabled");
        $on = filter_var($saved, FILTER_VALIDATE_BOOLEAN);

        return $on && $this->clientId($platform) !== '' && $this->hasSecret($platform);
    }

    public function setSecret(string $platform, string $secret): void
    {
        SystemSetting::set(
            "social_connect_{$platform}_client_secret",
            $secret === '' ? '' : Crypt::encryptString($secret),
            'social_connect'
        );
    }

    /** What the profile page needs to decide which "Connect with …" buttons to show. */
    public function publicConfig(): array
    {
        $out = [];
        foreach (self::PLATFORMS as $p) {
            $out[$p] = ['enabled' => $this->enabled($p), 'label' => self::labels()[$p]];
        }

        return $out;
    }

    /** Full view for the Super Admin settings form (no secret values leak). */
    public function adminConfig(): array
    {
        $out = [];
        foreach (self::PLATFORMS as $p) {
            $out[$p] = [
                'enabled' => filter_var(SystemSetting::get("social_connect_{$p}_enabled"), FILTER_VALIDATE_BOOLEAN),
                'client_id' => $this->clientId($p),
                'has_secret' => $this->hasSecret($p),
                'label' => self::labels()[$p],
            ];
        }

        return $out;
    }
}

<?php

namespace App\Services\Auth;

use App\Models\SystemSetting;

/**
 * Google / Apple sign-in settings, managed by Super Admin in Admin → Settings.
 * A value saved there wins; the .env value (GOOGLE_CLIENT_ID / APPLE_CLIENT_ID)
 * is only the fallback so existing installs keep working.
 */
class SocialAuthSettings
{
    public const GOOGLE_ID_PATTERN = '/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/';
    public const APPLE_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.\-]{2,}$/';

    public function clientId(string $provider): string
    {
        $saved = trim((string) SystemSetting::get("auth_{$provider}_client_id", ''));
        $value = $saved !== '' ? $saved : trim((string) config("services.{$provider}.client_id"));

        return str_starts_with($value, 'YOUR_') ? '' : $value;
    }

    /** Enabled = switched on AND a client ID exists. */
    public function enabled(string $provider): bool
    {
        $saved = SystemSetting::get("auth_{$provider}_enabled");
        // Never saved: on when .env already has a client ID (keeps current behaviour).
        $on = $saved === null ? $this->clientId($provider) !== '' : filter_var($saved, FILTER_VALIDATE_BOOLEAN);

        return $on && $this->clientId($provider) !== '';
    }

    /** Apple's popup needs the return URL registered on the Services ID. */
    public function appleRedirectUri(): string
    {
        $saved = trim((string) SystemSetting::get('auth_apple_redirect_uri', ''));

        return $saved !== '' ? $saved : rtrim((string) config('platform.frontendUrl'), '/') . '/login';
    }

    /** What the login / register pages need (public, no secrets). */
    public function publicConfig(): array
    {
        return [
            'google' => [
                'enabled' => $this->enabled('google'),
                'client_id' => $this->enabled('google') ? $this->clientId('google') : null,
            ],
            'apple' => [
                'enabled' => $this->enabled('apple'),
                'client_id' => $this->enabled('apple') ? $this->clientId('apple') : null,
                'redirect_uri' => $this->enabled('apple') ? $this->appleRedirectUri() : null,
            ],
        ];
    }

    /** Full view for the Super Admin settings form. */
    public function adminConfig(): array
    {
        return [
            'google' => [
                'enabled' => $this->enabled('google'),
                'client_id' => $this->clientId('google'),
                'source' => trim((string) SystemSetting::get('auth_google_client_id', '')) !== '' ? 'settings' : ($this->clientId('google') !== '' ? 'env' : 'none'),
            ],
            'apple' => [
                'enabled' => $this->enabled('apple'),
                'client_id' => $this->clientId('apple'),
                'redirect_uri' => $this->appleRedirectUri(),
                'source' => trim((string) SystemSetting::get('auth_apple_client_id', '')) !== '' ? 'settings' : ($this->clientId('apple') !== '' ? 'env' : 'none'),
            ],
        ];
    }
}

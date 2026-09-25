<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SocialAuthSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Google / Apple sign-in: Super Admin switches each provider on or off and
 * sets its client ID (Admin → Settings). The login and register pages read
 * the public config to decide which buttons to show.
 */
class AuthProviderSettingsController extends Controller
{
    public function __construct(private SocialAuthSettings $settings)
    {
    }

    /** GET /config/auth-providers — public, no secrets. */
    public function publicConfig(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->settings->publicConfig()])
            // Never cached: a Super Admin change must show on the next page load.
            ->header('Cache-Control', 'no-store');
    }

    /** GET /admin/auth-providers — Super Admin. */
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->settings->adminConfig()]);
    }

    /**
     * PUT /admin/auth-providers
     * { google: { enabled, client_id }, apple: { enabled, client_id, redirect_uri } }
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'google.enabled' => 'required|boolean',
            'google.client_id' => ['nullable', 'string', 'max:255', 'regex:' . SocialAuthSettings::GOOGLE_ID_PATTERN],
            'apple.enabled' => 'required|boolean',
            'apple.client_id' => ['nullable', 'string', 'max:255', 'regex:' . SocialAuthSettings::APPLE_ID_PATTERN, 'not_regex:/googleusercontent/i'],
            'apple.redirect_uri' => 'nullable|url|max:500',
        ], [
            'google.client_id.regex' => 'That is not a Google Client ID. It looks like 1234567890-abc123.apps.googleusercontent.com.',
            'apple.client_id.regex' => 'Enter your Apple Services ID, e.g. com.ebizearn.web.',
            'apple.client_id.not_regex' => 'That is a Google Client ID. Apple needs its own Services ID, e.g. com.ebizearn.web.',
            'apple.redirect_uri.url' => 'The Apple return URL must be a full https:// address.',
        ]);

        foreach (['google', 'apple'] as $provider) {
            $clientId = trim((string) ($data[$provider]['client_id'] ?? ''));
            if ($data[$provider]['enabled'] && $clientId === '' && $this->settings->clientId($provider) === '') {
                return response()->json([
                    'success' => false,
                    'message' => ucfirst($provider) . ' sign-in needs a client ID before it can be turned on.',
                    'errors' => ["{$provider}.client_id" => ['Required to turn ' . ucfirst($provider) . ' sign-in on.']],
                ], 422);
            }
        }

        $before = $this->settings->adminConfig();

        foreach (['google', 'apple'] as $provider) {
            SystemSetting::set("auth_{$provider}_enabled", $data[$provider]['enabled'] ? '1' : '0', 'auth');
            if (array_key_exists('client_id', $data[$provider])) {
                SystemSetting::set("auth_{$provider}_client_id", trim((string) $data[$provider]['client_id']), 'auth');
            }
        }
        if (array_key_exists('redirect_uri', $data['apple'])) {
            SystemSetting::set('auth_apple_redirect_uri', trim((string) $data['apple']['redirect_uri']), 'auth');
        }

        $after = $this->settings->adminConfig();
        AuditLogger::log($request->user(), 'settings.auth_providers_updated', SystemSetting::class, null, [], $before, $after);

        $on = collect(['google' => 'Google', 'apple' => 'Apple'])->filter(fn ($l, $p) => $after[$p]['enabled'])->values();

        return response()->json([
            'success' => true,
            'message' => $on->isEmpty()
                ? 'Saved. Social sign-in is off — only email sign-in is shown.'
                : 'Saved. ' . $on->implode(' and ') . ' sign-in is live on the login and register pages.',
            'data' => $after,
        ]);
    }
}

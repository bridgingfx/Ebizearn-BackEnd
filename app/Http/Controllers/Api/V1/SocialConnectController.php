<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialChannel;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Social\SocialConnectSettings;
use App\Services\Social\SocialOAuthService;
use App\Services\Social\SocialRoboVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * "Connect with TikTok / X / Facebook / YouTube" for contributor social
 * channels. The contributor logs in on the platform's own site; we only ever
 * hold an OAuth token (encrypted) — never their platform password.
 *
 * A successful handshake proves account ownership, so our robo marks the
 * channel verified on the spot and re-checks it daily afterwards.
 */
class SocialConnectController extends Controller
{
    public function __construct(
        private SocialConnectSettings $connect,
        private SocialOAuthService $oauth,
        private SocialRoboVerifier $robo,
    ) {
    }

    /** GET /config/social-connect — public: which "Connect with …" buttons to show. */
    public function publicConfig(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->connect->publicConfig()])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * GET /social-connect/{platform}/redirect — start the platform login.
     * Returns the authorize URL; the frontend opens it in a popup.
     */
    public function redirect(Request $request, string $platform): JsonResponse
    {
        $this->guardPlatform($platform);

        return response()->json([
            'success' => true,
            'data' => ['url' => $this->oauth->authorizationUrl($platform, $request->user()->id)],
        ]);
    }

    /**
     * GET /oauth/social/{platform}/callback — the platform sends the user back
     * here. Links (or creates) the channel, the robo verifies it, and the user
     * lands back on their profile with the result.
     */
    public function callback(Request $request, string $platform): RedirectResponse
    {
        $front = rtrim((string) config('platform.frontendUrl'), '/') . '/app/profile?tab=socials';
        $fail = fn (string $reason) => redirect()->to($front . '&sc_error=' . $reason);

        if (!in_array($platform, SocialConnectSettings::PLATFORMS, true) || !$this->connect->enabled($platform)) {
            return $fail('unavailable');
        }
        if ($request->filled('error')) {
            return $fail('denied'); // user pressed "cancel" on the platform's site
        }
        $code = (string) $request->input('code', '');
        $state = (string) $request->input('state', '');
        if ($code === '' || $state === '') {
            return $fail('invalid');
        }

        try {
            $ctx = $this->oauth->readState($platform, $state);
            $user = User::find($ctx['user_id']);
            abort_unless($user, 400, 'Account not found.');

            $tokens = $this->oauth->exchangeCode($platform, $code, $ctx['verifier']);
            $profile = $this->oauth->fetchProfile($platform, $tokens['access_token']);

            // One platform account → one eBizEarn account.
            $taken = SocialChannel::where('platform', $platform)
                ->where('oauth_provider_user_id', $profile['provider_user_id'])
                ->where('user_id', '!=', $user->id)
                ->exists();
            if ($taken) {
                return $fail('taken');
            }

            $channel = $user->socialChannels()->updateOrCreate(
                ['platform' => $platform],
                [
                    'handle' => $profile['username'] !== '' ? $profile['username'] : ('user-' . substr($profile['provider_user_id'], -6)),
                    'profile_url' => $profile['profile_url'] ?? 'https://example.invalid',
                    'connected_via' => 'oauth',
                    'oauth_provider_user_id' => $profile['provider_user_id'],
                    'oauth_username' => $profile['username'],
                    'oauth_access_token' => Crypt::encryptString($tokens['access_token']),
                    'oauth_refresh_token' => $tokens['refresh_token'] ? Crypt::encryptString($tokens['refresh_token']) : null,
                    'oauth_expires_at' => $tokens['expires_in'] ? now()->addSeconds($tokens['expires_in']) : null,
                    'oauth_scopes' => $tokens['scopes'] ?? null,
                    'verification_code' => null,
                    'rejection_reason' => null,
                    'submitted_at' => now(),
                ]
            );

            $this->robo->verifyNow($channel->fresh());

            AuditLogger::log($user, 'social_channel.oauth_connected', SocialChannel::class, $channel->id,
                ['platform' => $platform, 'oauth_username' => $profile['username']]);

            return redirect()->to($front . '&sc_connected=' . $platform);
        } catch (\Throwable $e) {
            Log::warning('Social OAuth callback failed', ['platform' => $platform, 'error' => $e->getMessage()]);

            return $fail('failed');
        }
    }

    // ------------------------------------------------------------------
    // Super Admin
    // ------------------------------------------------------------------

    /** GET /admin/social-connect */
    public function adminShow(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->connect->adminConfig()]);
    }

    /**
     * PUT /admin/social-connect
     * { tiktok: { enabled, client_id, client_secret? }, x: {...}, facebook: {...}, google: {...} }
     * Omit client_secret to keep the saved one; send "" with clear_secret=true to remove it.
     */
    public function adminUpdate(Request $request): JsonResponse
    {
        $rules = [];
        foreach (SocialConnectSettings::PLATFORMS as $p) {
            $rules["{$p}.enabled"] = 'required|boolean';
            $rules["{$p}.client_id"] = 'nullable|string|max:255';
            $rules["{$p}.client_secret"] = 'nullable|string|max:500';
            $rules["{$p}.clear_secret"] = 'nullable|boolean';
        }
        $data = $request->validate($rules);

        foreach (SocialConnectSettings::PLATFORMS as $p) {
            $row = $data[$p];
            if ($row['enabled'] && trim((string) ($row['client_id'] ?? '')) === '' && $this->connect->clientId($p) === '') {
                return response()->json([
                    'success' => false,
                    'message' => SocialConnectSettings::labels()[$p] . ' needs a Client ID before it can be turned on.',
                ], 422);
            }
            if ($row['enabled'] && !$this->connect->hasSecret($p)
                && trim((string) ($row['client_secret'] ?? '')) === '' && empty($row['clear_secret'])) {
                return response()->json([
                    'success' => false,
                    'message' => SocialConnectSettings::labels()[$p] . ' needs its Client Secret before it can be turned on.',
                ], 422);
            }
        }

        $before = $this->connect->adminConfig();
        foreach (SocialConnectSettings::PLATFORMS as $p) {
            $row = $data[$p];
            SystemSetting::set("social_connect_{$p}_enabled", $row['enabled'] ? '1' : '0', 'social_connect');
            if (array_key_exists('client_id', $row) && $row['client_id'] !== null) {
                SystemSetting::set("social_connect_{$p}_client_id", trim((string) $row['client_id']), 'social_connect');
            }
            if (!empty($row['clear_secret'])) {
                $this->connect->setSecret($p, '');
            } elseif (array_key_exists('client_secret', $row) && $row['client_secret'] !== null && trim((string) $row['client_secret']) !== '') {
                $this->connect->setSecret($p, trim((string) $row['client_secret']));
            }
        }

        $after = $this->connect->adminConfig();
        AuditLogger::log($request->user(), 'settings.social_connect_updated', SystemSetting::class, null, [], $before, $after);

        return response()->json(['success' => true, 'message' => 'Social connect settings saved.', 'data' => $after]);
    }

    private function guardPlatform(string $platform): void
    {
        abort_unless(in_array($platform, SocialConnectSettings::PLATFORMS, true), 404, 'Unknown platform.');
        abort_unless($this->connect->enabled($platform), 422,
            SocialConnectSettings::labels()[$platform] . ' login is not set up yet.');
    }
}

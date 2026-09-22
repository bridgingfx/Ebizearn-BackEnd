<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FraudEvent;
use App\Models\Profile;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Email\EmailService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Register a new Contributor or Business account.
     *
     * Phase 2: only contributor/business may self-register. Privileged
     * roles (admin, moderator, superadmin) are rejected here with 422 —
     * they are created exclusively by Super Admin (ops API / artisan).
     */
    public function register(Request $request, EmailService $emails): JsonResponse
    {
        // Explicit guard (defense in depth): the `in:` rule below also
        // rejects these, but a privileged role must fail with a clear,
        // deliberate message — never silently.
        $requestedRole = strtolower(trim((string) $request->input('role', '')));
        if (in_array($requestedRole, ['admin', 'moderator', 'superadmin', 'super_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This role cannot be registered publicly. Please contact platform support.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:contributor,business',
            'country_code' => 'nullable|string|max:4',
            'referral_code' => 'nullable|string|max:32',
            'company_name' => 'required_if:role,business|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // Check referrer
        $referrer = null;
        if (!empty($validated['referral_code'])) {
            $referrer = User::where('referral_code', strtoupper($validated['referral_code']))->first();
        }

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'status' => 'active',
            'referrer_id' => $referrer?->id,
            'email_verified_at' => now(), // Demo/default verified
        ]);

        // Create Profile
        Profile::create([
            'user_id' => $user->id,
            'country_code' => $validated['country_code'] ?? 'AE',
            'language' => 'en',
            'contributor_level' => 'starter',
            'fraud_score' => 0,
        ]);

        // Create Wallet
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'available_balance_cents' => 0,
            'pending_balance_cents' => 0,
            'lifetime_earnings_cents' => 0,
            'total_withdrawn_cents' => 0,
        ]);

        // Create Business profile if business role
        if ($user->role === 'business') {
            Business::create([
                'owner_id' => $user->id,
                'company_name' => $validated['company_name'] ?? $user->name . ' Co',
                'status' => 'active',
            ]);
        }

        // Phase 8: resolve the multi-level referral chain (?ref= code).
        // One Referral row per level; rewards are paid only after the
        // qualification rules (see ReferralService::qualifyAndReward).
        if ($referrer) {
            app(\App\Services\Referral\ReferralService::class)->buildChain($user->load('referrer'));
        }

        $emails->sendEvent('welcome_' . $user->role, $user->email, ['user_name' => $user->name]);

        // Phase 2 / Priority 7 — log the registration + screen for
        // duplicate accounts from the same IP/device (flagged for review,
        // never auto-blocked).
        $this->screenRegistrationForDuplicates($request, $user);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'data' => [
                'user' => $user->load(['profile', 'wallet', 'business']),
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * Log in with credentials and return token & full state.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'portal' => 'nullable|in:contributor,business,moderator,superadmin',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if ($user->status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended for compliance review. Contact support@ebizearn.com.',
            ], 403);
        }

        // Portal separation (server-side, never frontend-only): when the
        // sign-in request names a portal, the user's role must belong to
        // that portal. This runs BEFORE any token revocation or minting so
        // a portal mismatch never destroys existing sessions and never
        // issues a token for the wrong portal.
        $portalRoles = [
            'contributor' => ['contributor'],
            'business' => ['business'],
            'moderator' => ['moderator'],
            'superadmin' => ['superadmin'],
        ];

        $portal = $request->input('portal');
        if ($portal !== null && $portal !== '' && !in_array($user->role, $portalRoles[$portal], true)) {
            return response()->json([
                'success' => false,
                'message' => "This account does not belong to the {$portal} portal. Please use the correct sign-in.",
            ], 403);
        }

        // Single-active-session: revoke all prior tokens before minting the new
        // one. This kills leaked/stale tokens the moment the legitimate user
        // logs in and prevents indefinite session sprawl (the SPA stores a
        // single token in localStorage, so multi-device concurrency is not a
        // designed feature). Chosen over "prune expired only" because expiry
        // alone leaves live-but-abandoned tokens valid for up to 7 days.
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        // Phase 2 / Priority 7 — device/IP risk logging on every login.
        // Honest telemetry: routine logins are recorded as reviewed/info;
        // a never-before-seen device fingerprint for this user is flagged
        // for moderator review. Heuristics never block the login itself.
        $this->logLoginRisk($request, $user);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user->load(['profile', 'wallet', 'business']),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Create a password reset token. Local/dev responses include the reset URL for testing without SMTP.
     */
    public function forgotPassword(Request $request, EmailService $emails): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();
        $resetUrl = null;

        if ($user) {
            $token = Password::broker()->createToken($user);
            $resetUrl = rtrim((string) config('platform.frontendUrl'), '/')
                . '/reset-password?email=' . urlencode($user->email)
                . '&token=' . urlencode($token);

            $emails->sendEvent('forgot_password', $user->email, [
                'user_name' => $user->name,
                'reset_url' => $resetUrl,
            ]);
        }

        $payload = [
            'success' => true,
            'message' => 'If that email exists, password reset instructions are ready.',
            'data' => [],
        ];

        if ($resetUrl && app()->environment(['local', 'testing'])) {
            $payload['data']['reset_url'] = $resetUrl;
        }

        return response()->json($payload);
    }

    /**
     * Reset an account password with a valid token.
     */
    public function resetPassword(Request $request, EmailService $emails): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Password::broker()->tokenExists($user, $request->token)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token.',
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($request->password),
            'remember_token' => Str::random(60),
        ])->save();

        Password::broker()->deleteToken($user);
        $user->tokens()->delete();

        $emails->sendEvent('password_changed', $user->email, ['user_name' => $user->name]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Please log in with your new password.',
        ]);
    }

    /**
     * Log out and revoke current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get current authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $request->user()->load(['profile', 'wallet', 'business']),
            ],
        ]);
    }

    /**
     * Phase 2 / Priority 7 — device/IP risk logging on login.
     *
     * Every successful login writes a FraudEvent row (routine logins are
     * `reviewed`/low so they stay out of the flagged queue). A device
     * fingerprint never seen for this user is recorded as `flagged`/medium
     * for moderator review. This is honest telemetry — it never blocks the
     * login and never claims automated fraud verdicts.
     */
    protected function logLoginRisk(Request $request, User $user): void
    {
        $ip = $request->ip();
        $userAgent = (string) $request->userAgent();
        $fingerprint = hash('sha256', ($ip ?? 'unknown') . '|' . $userAgent);

        $seenBefore = FraudEvent::where('user_id', $user->id)
            ->whereIn('event_type', ['login', 'new_device_login', 'registration'])
            ->where('details_json->fingerprint', $fingerprint)
            ->exists();

        $isNewDevice = !$seenBefore;

        FraudEvent::create([
            'user_id' => $user->id,
            'event_type' => $isNewDevice ? 'new_device_login' : 'login',
            'severity' => $isNewDevice ? 'medium' : 'low',
            'details_json' => [
                'fingerprint' => $fingerprint,
                'ip' => $ip,
                'first_seen_for_user' => $isNewDevice,
            ],
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'status' => $isNewDevice ? 'flagged' : 'reviewed',
        ]);

        $user->forceFill([
            'last_login_ip' => $ip,
            'last_login_at' => now(),
        ])->save();
    }

    /**
     * Phase 2 / Priority 7 — duplicate-account screen at registration.
     *
     * Counts distinct users registered from the same IP in the trailing
     * 30 days. At 3+ accounts the new registration is still allowed (we
     * do not punish shared networks automatically) but a `multi_account`
     * FraudEvent is flagged for moderator review. The registration row
     * itself is logged so future screens have data to compare against.
     */
    protected function screenRegistrationForDuplicates(Request $request, User $user): void
    {
        $ip = $request->ip();
        $userAgent = (string) $request->userAgent();
        $fingerprint = hash('sha256', ($ip ?? 'unknown') . '|' . $userAgent);

        $user->forceFill(['registration_ip' => $ip])->save();

        FraudEvent::create([
            'user_id' => $user->id,
            'event_type' => 'registration',
            'severity' => 'low',
            'details_json' => ['fingerprint' => $fingerprint, 'ip' => $ip],
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'status' => 'reviewed',
        ]);

        $recentSameIp = User::where('registration_ip', $ip)
            ->where('id', '!=', $user->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        if ($recentSameIp >= 2) {
            FraudEvent::create([
                'user_id' => $user->id,
                'event_type' => 'multi_account',
                'severity' => 'medium',
                'details_json' => [
                    'fingerprint' => $fingerprint,
                    'ip' => $ip,
                    'distinct_users_same_ip_30d' => $recentSameIp + 1,
                    'note' => '3+ accounts registered from one IP in 30 days. Shared networks exist — moderator review, not an automated verdict.',
                ],
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'status' => 'flagged',
            ]);
        }
    }
}



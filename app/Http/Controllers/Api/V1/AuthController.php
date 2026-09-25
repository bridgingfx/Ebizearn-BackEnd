<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FraudEvent;
use App\Models\Profile;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Rules\PhoneCountryCode;
use App\Rules\StrongPassword;
use App\Exceptions\EmailOtpException;
use App\Services\Auth\EmailOtpService;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\SocialTokenVerifier;
use App\Services\Auth\SocialTokenVerificationException;
use App\Services\Email\EmailService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /** Which roles may sign in on each portal (password and social sign-in). */
    private const PORTAL_ROLES = [
        'contributor' => ['contributor'],
        'business' => ['business'],
        'moderator' => ['moderator', 'admin'],
        'superadmin' => ['superadmin'],
    ];

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
            // Round 2: strong password policy (min 10 chars, mixed classes).
            'password' => ['required', 'string', new StrongPassword()],
            'role' => 'required|in:contributor,business',
            'country_code' => 'nullable|string|max:4',
            'referral_code' => 'nullable|string|max:32',
            'company_name' => 'required_if:role,business|nullable|string|max:255',
            // Signup hardening: phone is mandatory on email signup. The
            // country code must be a real dial code from the allow-list
            // (config/phone.php); the number is digits only, 4-15 chars.
            // Persisted as a single E.164 value on users.phone.
            'phone_country_code' => ['required', 'string', new PhoneCountryCode()],
            'phone_number' => ['required', 'string', 'regex:/^\d{4,15}$/'],
        ], [
            'phone_number.regex' => 'The phone number must contain 4-15 digits only.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $phone = EmailOtpService::normalizePhone(
            $validated['phone_country_code'],
            $validated['phone_number'],
        );

        if ($phone === null) {
            // Unreachable through the validator above (defense in depth).
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => ['phone_country_code' => ['The selected phone country code is invalid.']],
            ], 422);
        }

        // Check referrer
        $referrer = null;
        if (!empty($validated['referral_code'])) {
            $referrer = User::where('referral_code', strtoupper($validated['referral_code']))->first();
        }

        // Signup hardening: the account is created PENDING verification —
        // no Sanctum token is issued here. The OTP email goes out below;
        // POST /api/v1/auth/otp/verify activates the account and logs the
        // user in. Everything runs in one transaction so a failed email
        // send never leaves a half-created account behind.
        try {
            $result = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $referrer, $phone, $emails, $request) {
                $user = User::create([
                    'uuid' => (string) Str::uuid(),
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $phone,
                    'password' => Hash::make($validated['password']),
                    'role' => $validated['role'],
                    'status' => 'pending_verification',
                    'referrer_id' => $referrer?->id,
                    'email_verified_at' => null,
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
                Wallet::create([
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

                // Signup hardening — OTP email. A mailer outage FAILS the
                // registration loudly (503): the transaction rolls back, no
                // half-created account, no silently-missing code. The server
                // MUST have working SMTP (see MAIL_* in .env.example).
                $expiresIn = app(EmailOtpService::class)->issue($user, $request->ip());

                // Phase 2 / Priority 7 — log the registration + screen for
                // duplicate accounts from the same IP/device (flagged for review,
                // never auto-blocked).
                $this->screenRegistrationForDuplicates($request, $user);

                return [$user, $expiresIn];
            });
        } catch (EmailOtpException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->status);
        }

        [$user, $expiresIn] = $result;

        return response()->json([
            'success' => true,
            'message' => 'Account created. Enter the 6-digit code sent to your email to activate it.',
            'data' => [
                'user' => $user->load(['profile', 'wallet', 'business'])->withClientPermissions(),
                'requires_otp' => true,
                'otp' => [
                    'expires_in_seconds' => $expiresIn,
                    'resend_cooldown_seconds' => EmailOtpService::RESEND_COOLDOWN_SECONDS,
                ],
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
            // Round 2 — failed-attempt telemetry. Never reveals whether the
            // email exists (same generic message either way).
            $this->logFailedLogin($request, $user);

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

        // Signup hardening: an email signup stays pending until the OTP is
        // verified — password login cannot bypass the OTP gate. Pre-existing
        // accounts (status active, even if unverified) are unaffected, and
        // Google OAuth users are verified out-of-band.
        if ($user->status === 'pending_verification' && !$user->email_verified_at) {
            return response()->json([
                'success' => false,
                'code' => 'email_unverified',
                'message' => 'Please verify the 6-digit code sent to your email to activate your account.',
            ], 403);
        }

        // Portal separation (server-side, never frontend-only): when the
        // sign-in request names a portal, the user's role must belong to
        // that portal. This runs BEFORE any token revocation or minting so
        // a portal mismatch never destroys existing sessions and never
        // issues a token for the wrong portal.
        $portal = $request->input('portal');
        if ($portal !== null && $portal !== '' && !in_array($user->role, self::PORTAL_ROLES[$portal], true)) {
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
                'user' => $user->load(['profile', 'wallet', 'business'])->withClientPermissions(),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Round 2 — social sign-in (Google / Apple).
     *
     * POST /api/v1/auth/social/{provider} with { "id_token": "..." }.
     * The ID token is verified server-side (signature, aud, exp, iss).
     * Find-or-create: first by (provider, sub), else by verified email
     * (links the provider to the existing account), else creates a fresh
     * contributor account. Returns the same shape as password login.
     */
    public function socialLogin(
        Request $request,
        string $provider,
        SocialTokenVerifier $verifier,
        EmailVerificationService $verification,
        EmailService $emails,
    ): JsonResponse {
        $provider = strtolower($provider);
        if (!in_array($provider, ['google', 'apple'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unsupported social provider.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'id_token' => 'required|string',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            // Which sign-in / sign-up page the button was on.
            'portal' => 'nullable|in:contributor,business,moderator,superadmin',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $portal = $request->input('portal') ?: null;
        $label = ucfirst($provider);

        // Staff consoles are email + password only.
        if (in_array($portal, ['moderator', 'superadmin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Staff sign in with their work email and password only.',
            ], 403);
        }

        // Super Admin can switch each provider off in Admin → Settings.
        if (!app(\App\Services\Auth\SocialAuthSettings::class)->enabled($provider)) {
            return response()->json([
                'success' => false,
                'message' => "{$label} sign-in is turned off. Please use your email and password.",
            ], 403);
        }

        try {
            $claims = $provider === 'google'
                ? $verifier->verifyGoogle($request->input('id_token'))
                : $verifier->verifyApple($request->input('id_token'));
        } catch (SocialTokenVerificationException $e) {
            $notConfigured = str_contains($e->getMessage(), 'not configured');
            Log::warning('Social login token rejected', ['provider' => $provider, 'reason' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => $notConfigured
                    ? "{$label} sign-in is not available right now. Please use your email and password."
                    : "{$label} sign-in failed. Please try again.",
            ], $notConfigured ? 503 : 401);
        }

        // 1. Already linked? Sign straight in.
        $social = SocialAccount::where('provider', $provider)
            ->where('provider_sub', $claims['sub'])
            ->first();

        $user = $social?->user;
        $isNewUser = false;

        if (!$user) {
            // 2. Email match? Link the provider to the existing account —
            // but only when the provider asserts the email is verified,
            // otherwise anyone could claim someone else's address.
            $email = $claims['email'] ?? $request->input('email');
            if ($email && $claims['email_verified'] === true) {
                $user = User::where('email', $email)->first();
            }

            // 3. Fresh account (default role: contributor). If the claimed email
            // belongs to someone else (unverified claim), it cannot be
            // reused — mint a deterministic placeholder instead of violating
            // the unique email constraint.
            if (!$user) {
                // Staff accounts are created by a Super Admin, never by social sign-up.
                if (in_array($portal, ['moderator', 'superadmin'], true)) {
                    return response()->json([
                        'success' => false,
                        'message' => "No staff account is linked to this {$label} account. Sign in with your staff email and password.",
                    ], 403);
                }

                if ($email && User::where('email', $email)->exists()) {
                    $email = $this->placeholderEmail($provider, $claims['sub']);
                }
                $displayName = $claims['name']
                    ?? $request->input('name')
                    ?? ($email ? Str::before($email, '@') : 'eBizEarn member');

                $user = User::create([
                    'uuid' => (string) Str::uuid(),
                    'name' => $displayName,
                    'email' => $email ?? $this->placeholderEmail($provider, $claims['sub']),
                    // No usable password: social-only account. A random
                    // 40-char secret means password login is impossible.
                    'password' => Hash::make(Str::random(40)),
                    // Signing up from the business pages creates a business account.
                    'role' => $portal === 'business' ? 'business' : 'contributor',
                    'status' => 'active',
                    // The provider already verified this address out-of-band.
                    'email_verified_at' => $claims['email_verified'] === true ? now() : null,
                ]);

                Profile::create([
                    'user_id' => $user->id,
                    'country_code' => 'AE',
                    'language' => 'en',
                    'contributor_level' => 'starter',
                    'fraud_score' => 0,
                ]);

                Wallet::create([
                    'user_id' => $user->id,
                    'currency' => 'USD',
                    'available_balance_cents' => 0,
                    'pending_balance_cents' => 0,
                    'lifetime_earnings_cents' => 0,
                    'total_withdrawn_cents' => 0,
                ]);

                if ($user->role === 'business') {
                    // Company details can be completed later in Business → Settings.
                    Business::create([
                        'owner_id' => $user->id,
                        'company_name' => $user->name . ' Co',
                        'status' => 'active',
                    ]);
                }

                $isNewUser = true;
                $this->screenRegistrationForDuplicates($request, $user);
                $emails->sendEvent('welcome_' . $user->role, $user->email, ['user_name' => $user->name]);

                // Round 2: unverified social accounts get the verification
                // email (Apple hides email after first auth; provider-
                // verified ones skip it).
                if (!$user->email_verified_at) {
                    try {
                        $verification->issue($user);
                    } catch (Exception $e) {
                        Log::warning('Verification email failed at social registration', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_sub' => $claims['sub'],
                'email' => $email ?? null,
                'linked_at' => now(),
            ]);
        }

        // Same portal rule as password login: the account's role must belong
        // to the page it signed in from (checked before any token is issued).
        if ($portal !== null && !in_array($user->role, self::PORTAL_ROLES[$portal], true)) {
            $home = ['contributor' => 'contributor', 'business' => 'business', 'moderator' => 'staff', 'admin' => 'staff', 'superadmin' => 'admin console'][$user->role] ?? $user->role;

            return response()->json([
                'success' => false,
                'message' => "This {$label} account is registered as a {$home} account. Please use the {$home} sign-in.",
            ], 403);
        }

        if ($user->status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended for compliance review. Contact support@ebizearn.com.',
            ], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->logLoginRisk($request, $user);

        return response()->json([
            'success' => true,
            'message' => $isNewUser ? "Welcome! Your account was created with {$label}." : 'Signed in successfully.',
            'data' => [
                'user' => $user->load(['profile', 'wallet', 'business'])->withClientPermissions(),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Apple may withhold the email entirely (private relay / hidden after
     * first auth). The address must still be unique and recognizable, so we
     * mint a deterministic placeholder the user can replace later.
     */
    protected function placeholderEmail(string $provider, string $sub): string
    {
        return $provider . '_' . substr(hash('sha256', $sub), 0, 16) . '@users.ebizearn.internal';
    }

    /**
     * Round 2 — verify an email address with the token from the email URL.
     *
     * POST /api/v1/auth/email/verify { "token": "..." }
     */
    public function verifyEmail(Request $request, EmailVerificationService $verification): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $verification->verify($request->input('token'));

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification token.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.',
            'data' => [
                'user' => $user->load(['profile', 'wallet', 'business'])->withClientPermissions(),
            ],
        ]);
    }

    /**
     * Round 2 — resend the verification email (authenticated, throttled).
     *
     * POST /api/v1/auth/email/resend
     */
    public function resendVerificationEmail(Request $request, EmailVerificationService $verification): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Email is already verified.',
            ], 422);
        }

        try {
            $verification->issue($user);
        } catch (Exception $e) {
            Log::warning('Verification resend failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not send the verification email. Please try again later.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification email sent.',
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
            // Round 2: resets must meet the same strong-password policy.
            'password' => ['required', 'string', new StrongPassword(), 'confirmed'],
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
                'user' => $request->user()->load(['profile', 'wallet', 'business'])->withClientPermissions(),
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

        // Round 2 — country mismatch: when the request carries a
        // Cloudflare country header (production is behind Cloudflare) and it
        // disagrees with the profile country, flag for moderator review.
        // Telemetry only — the login is never blocked.
        $edgeCountry = strtoupper((string) $request->header('CF-IPCountry'));
        $profileCountry = strtoupper((string) $user->profile?->country_code);
        if ($edgeCountry !== '' && $edgeCountry !== 'XX' && $profileCountry !== '' && $edgeCountry !== $profileCountry) {
            FraudEvent::create([
                'user_id' => $user->id,
                'event_type' => 'country_mismatch',
                'severity' => 'medium',
                'details_json' => [
                    'edge_country' => $edgeCountry,
                    'profile_country' => $profileCountry,
                    'ip' => $ip,
                    'note' => 'Login country differs from profile country. Travel and VPNs exist — moderator review, not an automated verdict.',
                ],
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'status' => 'flagged',
            ]);
        }
    }

    /**
     * Round 2 — failed-login telemetry (defense in depth behind the login
     * throttle limiter).
     *
     * Every failed attempt writes a `failed_login` row (low/reviewed). When
     * 5+ failures arrive from one IP within 10 minutes, a
     * `rapid_failed_logins` event is flagged medium for moderator review —
     * the signature of credential stuffing. Nothing here blocks the login;
     * the throttle middleware owns the actual backoff.
     */
    protected function logFailedLogin(Request $request, ?User $user): void
    {
        $ip = $request->ip();
        $userAgent = (string) $request->userAgent();

        FraudEvent::create([
            'user_id' => $user?->id,
            'event_type' => 'failed_login',
            'severity' => 'low',
            'details_json' => [
                'ip' => $ip,
                'email_known' => $user !== null,
            ],
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'status' => 'reviewed',
        ]);

        $recentFailures = FraudEvent::where('event_type', 'failed_login')
            ->where('ip_address', $ip)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        if ($recentFailures >= 5) {
            FraudEvent::create([
                'user_id' => $user?->id,
                'event_type' => 'rapid_failed_logins',
                'severity' => 'medium',
                'details_json' => [
                    'ip' => $ip,
                    'failed_attempts_10m' => $recentFailures,
                    'note' => '5+ failed logins from one IP in 10 minutes — possible credential stuffing. Moderator review, not an automated verdict.',
                ],
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'status' => 'flagged',
            ]);
        }
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



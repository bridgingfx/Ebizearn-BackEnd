<?php

namespace App\Services\Auth;

use App\Exceptions\EmailOtpException;
use App\Mail\EmailOtpMail;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\Email\EmailService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Email OTP verification for signup hardening.
 *
 * Flow: email signup creates the account unverified (status
 * pending_verification, no token) and issue() sends a 6-digit code.
 * verify() on the correct code flips the account active and the caller
 * mints the Sanctum token (user logged in).
 *
 * Policy (all enforced here, not in the controller):
 *  - 6-digit numeric code, bcrypt-hashed at rest, 10-minute expiry,
 *  - max 5 verify attempts, then the code is invalidated,
 *  - resend = fresh code, old pending codes invalidated,
 *  - 60-second resend cooldown,
 *  - max 5 sends/hour per email AND per IP.
 *
 * If the mailer is misconfigured (or SMTP dies), issue() deletes the
 * pending row and throws EmailOtpException('email_failed', 503) with a
 * LOUD log line — it NEVER pretends the email went out.
 */
class EmailOtpService
{
    public const CODE_TTL_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const MAX_SENDS_PER_HOUR = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Issue a fresh code for the user and email it. Returns seconds until
     * expiry (handy for the API response).
     *
     * @throws EmailOtpException not_found | already_verified | cooldown |
     *                           rate_limited | email_failed
     */
    public function issue(User $user, ?string $ip = null): int
    {
        if ($user->email_verified_at) {
            throw new EmailOtpException(
                'already_verified',
                'This email address is already verified. You can sign in.',
                409,
            );
        }

        $email = strtolower(trim($user->email));
        $now = now();

        // 60s resend cooldown, keyed on the newest pending code.
        $latest = EmailOtp::where('email', $email)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if ($latest && $latest->created_at->diffInSeconds($now) < self::RESEND_COOLDOWN_SECONDS) {
            $retryAfter = self::RESEND_COOLDOWN_SECONDS - $latest->created_at->diffInSeconds($now);

            throw new EmailOtpException(
                'cooldown',
                'A code was just sent. Please wait before requesting another.',
                429,
                ['retry_after_seconds' => $retryAfter],
            );
        }

        // 5 sends/hour per email AND per IP (spam / SMTP-budget abuse).
        $windowStart = $now->copy()->subHour();

        if (EmailOtp::where('email', $email)->where('created_at', '>=', $windowStart)->count() >= self::MAX_SENDS_PER_HOUR) {
            throw new EmailOtpException(
                'rate_limited',
                'Too many codes sent for this email. Please try again later.',
                429,
            );
        }

        if ($ip && EmailOtp::where('ip', $ip)->where('created_at', '>=', $windowStart)->count() >= self::MAX_SENDS_PER_HOUR) {
            throw new EmailOtpException(
                'rate_limited',
                'Too many codes requested. Please try again later.',
                429,
            );
        }

        // Invalidate older pending codes — only the newest can verify.
        EmailOtp::where('email', $email)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => $now]);

        $code = (string) random_int(100000, 999999);

        $otp = EmailOtp::create([
            'user_id' => $user->id,
            'email' => $email,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'ip' => $ip,
            'expires_at' => $now->copy()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        try {
            $this->sendMail($user, $code);
        } catch (Throwable $e) {
            // Loud failure: remove the pending row (so it can't count
            // against limits later) and throw — never pretend success.
            $otp->delete();

            Log::error('!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!');
            Log::error('EMAIL OTP DELIVERY FAILED — signup cannot complete without working SMTP.');
            Log::error('Configure MAIL_MAILER=smtp + MAIL_HOST/PORT/USERNAME/PASSWORD on the server.');
            Log::error('User: '.$user->email.' | Error: '.$e->getMessage());
            Log::error('!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!');

            $previous = $e instanceof EmailOtpException ? $e : null;

            throw new EmailOtpException(
                'email_failed',
                $previous?->getMessage()
                    ?? 'We could not send the verification email (mail server unavailable). Please try again later.',
                503,
                [],
                $e,
            );
        }

        return self::CODE_TTL_MINUTES * 60;
    }

    /**
     * Verify a code. On success marks the email verified and activates a
     * pending signup account. Returns the verified user.
     *
     * @throws EmailOtpException not_found | already_verified | expired |
     *                           invalid | too_many_attempts
     */
    public function verify(User $user, string $code): User
    {
        if ($user->email_verified_at) {
            throw new EmailOtpException(
                'already_verified',
                'This email address is already verified.',
                409,
            );
        }

        $email = strtolower(trim($user->email));
        $now = now();

        $otp = EmailOtp::where('email', $email)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (!$otp) {
            throw new EmailOtpException(
                'not_found',
                'No verification code found for this email. Request a new one.',
                404,
            );
        }

        if ($otp->isExpired()) {
            $otp->update(['invalidated_at' => $now]);

            throw new EmailOtpException(
                'expired',
                'This code has expired. Request a new one.',
                410,
            );
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->update(['invalidated_at' => $now]);

            throw new EmailOtpException(
                'too_many_attempts',
                'Too many wrong attempts. This code is invalidated — request a new one.',
                429,
            );
        }

        if (!Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            $remaining = self::MAX_ATTEMPTS - $otp->fresh()->attempts;

            if ($remaining <= 0) {
                $otp->update(['invalidated_at' => $now]);

                throw new EmailOtpException(
                    'too_many_attempts',
                    'Too many wrong attempts. This code is invalidated — request a new one.',
                    429,
                );
            }

            throw new EmailOtpException(
                'invalid',
                'Incorrect code. Please check the email and try again.',
                422,
                ['attempts_remaining' => $remaining],
            );
        }

        // Success: consume the code, verify the address, activate pending
        // signup accounts.
        $otp->update(['used_at' => $now]);

        EmailOtp::where('email', $email)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => $now]);

        $user->forceFill([
            'email_verified_at' => $now,
            'status' => $user->status === 'pending_verification' ? 'active' : $user->status,
        ])->save();

        return $user->fresh();
    }

    /**
     * Send the code via the active email provider (Admin → Email settings,
     * e.g. Brevo), falling back to Laravel's mailer. Throws loudly when the
     * mail cannot actually be delivered.
     */
    protected function sendMail(User $user, string $code): void
    {
        $emails = app(EmailService::class);
        $mailer = (string) config('mail.default');

        // A non-delivering driver in production pretends success while the
        // user never gets a code — fail LOUD instead.
        if (!$emails->activeDeliveringProvider() && app()->environment('production') && in_array($mailer, ['log', 'array'], true)) {
            // Operator detail goes to the log; the user sees a plain message.
            Log::error('Email delivery is not configured (MAIL_MAILER='.$mailer.'). '
                .'Activate a provider in Admin → Email settings (e.g. Brevo) or set a real SMTP mailer.');

            throw new EmailOtpException(
                'email_failed',
                'We could not send the verification email right now. Please try again in a few minutes.',
                503,
            );
        }

        $emails->sendMailable('email_otp', $user->email, new EmailOtpMail($user, $code));
    }

    /**
     * Normalize a dial code + national number into E.164.
     * Returns null when the code is not on the allow-list.
     */
    public static function normalizePhone(string $countryCode, string $number): ?string
    {
        $code = ltrim(trim($countryCode), '+');

        if (!in_array($code, config('phone.allowed_codes', []), true)) {
            return null;
        }

        if (!preg_match('/^\d{4,15}$/', trim($number))) {
            return null;
        }

        return '+'.$code.trim($number);
    }
}

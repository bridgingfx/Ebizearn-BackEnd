<?php

namespace App\Services\Auth;

use App\Mail\VerifyEmail;
use App\Models\User;
use App\Services\Email\EmailService;
use Illuminate\Support\Str;

/**
 * Round 2 — email verification token lifecycle.
 *
 * Design: the raw token (64 hex chars) is generated, handed to the user
 * inside the verification URL, and NEVER persisted. Only its SHA-256 digest
 * plus the issue timestamp are stored on the user row. Tokens live 24h.
 */
class EmailVerificationService
{
    public const TOKEN_TTL_HOURS = 24;

    /**
     * Issue a fresh verification token for the user and send the branded
     * verification email. Returns the frontend URL (raw token embedded).
     */
    public function issue(User $user): string
    {
        $raw = Str::random(64);

        $user->forceFill([
            'email_verification_token' => hash('sha256', $raw),
            'email_verification_sent_at' => now(),
        ])->save();

        $url = $this->verifyUrl($raw);

        app(EmailService::class)->sendMailable('verify_email', $user->email, new VerifyEmail($user, $url));

        return $url;
    }

    /**
     * Verify a raw token: digest lookup + 24h expiry + single use.
     * Returns the verified user, or null.
     */
    public function verify(string $rawToken): ?User
    {
        if ($rawToken === '') {
            return null;
        }

        $user = User::where('email_verification_token', hash('sha256', $rawToken))->first();

        if (!$user || !$user->email_verification_sent_at) {
            return null;
        }

        if ($user->email_verification_sent_at->lt(now()->subHours(self::TOKEN_TTL_HOURS))) {
            return null;
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verification_token' => null,
            'email_verification_sent_at' => null,
            // Signup hardening: verifying the address also activates an
            // account that was still pending OTP verification.
            'status' => $user->status === 'pending_verification' ? 'active' : $user->status,
        ])->save();

        return $user->fresh();
    }

    public function verifyUrl(string $rawToken): string
    {
        return rtrim((string) config('platform.frontendUrl'), '/')
            . '/verify-email?token=' . urlencode($rawToken);
    }
}

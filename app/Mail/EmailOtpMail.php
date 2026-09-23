<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Signup hardening — 6-digit email verification code.
 *
 * Sent via Laravel's mailer (SMTP in production). The code travels ONLY
 * inside this email; the database holds just its bcrypt hash. HTML +
 * plain-text parts both render from Blade. Honest copy: no claims about
 * earnings, no fake stats.
 */
class EmailOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your eBizEarn verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            text: 'emails.otp-text',
            with: [
                'userName' => $this->user->name,
                'code' => $this->code,
                'ttlMinutes' => \App\Services\Auth\EmailOtpService::CODE_TTL_MINUTES,
            ],
        );
    }
}

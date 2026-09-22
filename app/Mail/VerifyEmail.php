<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Round 2 — branded email-verification message.
 *
 * Sent via the configured mailer (SMTP in production, log/array locally).
 * The raw token travels only inside the email URL; the database holds just
 * its SHA-256 digest. HTML + plain-text parts both render from Blade.
 */
class VerifyEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $verifyUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your eBizEarn email address',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email',
            text: 'emails.verify-email-text',
            with: [
                'userName' => $this->user->name,
                'verifyUrl' => $this->verifyUrl,
            ],
        );
    }
}

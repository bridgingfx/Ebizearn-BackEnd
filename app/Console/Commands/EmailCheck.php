<?php

namespace App\Console\Commands;

use App\Mail\EmailOtpMail;
use App\Models\User;
use App\Services\Email\EmailService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnose email delivery on a server: shows which provider is used, whether
 * BREVO_API_KEY is loaded, and (with an address) sends a real test OTP email
 * and prints the provider's exact error.
 *
 *   php artisan email:check
 *   php artisan email:check you@example.com
 */
class EmailCheck extends Command
{
    protected $signature = 'email:check {to? : Send a test verification email to this address}';

    protected $description = 'Show the email delivery setup and optionally send a test email.';

    public function handle(EmailService $emails): int
    {
        $key = (string) config('services.brevo.key');

        $this->line('Environment      : ' . app()->environment());
        $this->line('Config cached    : ' . (app()->configurationIsCached() ? 'YES (run php artisan config:clear after editing .env)' : 'no'));
        $this->line('BREVO_API_KEY    : ' . ($key !== '' ? 'set (…' . substr($key, -4) . ')' : 'NOT SET'));
        $this->line('From address     : ' . config('services.brevo.from_email'));

        $provider = $emails->activeDeliveringProvider();
        $this->line('Sending through  : ' . ($provider ? $provider->name . ' [' . $provider->driver . ']' : 'NOTHING — emails are only written to the log'));

        $to = $this->argument('to');
        if (!$to) {
            return self::SUCCESS;
        }

        if (!$provider) {
            $this->error('Cannot send: add BREVO_API_KEY to .env, then run php artisan config:clear.');
            return self::FAILURE;
        }

        $user = new User(['name' => 'Test user', 'email' => $to]);

        try {
            $emails->sendMailable('email_check', $to, new EmailOtpMail($user, '123456'));
            $this->info("Sent a test verification email to {$to}.");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('FAILED: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}

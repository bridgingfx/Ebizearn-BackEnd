<?php

namespace App\Services\Email;

use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\EmailTemplate;
use Illuminate\Http\Client\Response;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class EmailService
{
    /**
     * Send an event email (template + active provider). Never throws: failures are logged.
     */
    public function sendEvent(string $eventKey, string $toEmail, array $variables = []): bool
    {
        try {
            $template = EmailTemplate::where('event_key', $eventKey)->first();

            if (!$template || !$template->is_enabled) {
                $this->log($eventKey, null, $toEmail, null, 'skipped', $template ? 'Template disabled' : 'Template not found');
                return false;
            }

            $variables = array_merge([
                'app_name' => config('app.name'),
                'support_email' => config('platform.supportEmail'),
                'login_url' => rtrim((string) config('platform.frontendUrl'), '/') . '/login',
            ], $variables);

            return $this->deliver(
                $eventKey,
                $this->currentProvider(),
                $toEmail,
                $this->render($template->subject, $variables, false),
                $this->render($template->html_body, $variables, true),
                $this->render($template->text_body, $variables, false),
            );
        } catch (Throwable $e) {
            Log::error('Email event failed', ['event' => $eventKey, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * The provider used for sending. BREVO_API_KEY in .env always wins (Brevo
     * HTTP API, no SMTP); otherwise the provider marked active in Admin → Email.
     */
    public function currentProvider(): ?EmailProvider
    {
        $key = (string) config('services.brevo.key');

        if ($key !== '') {
            return new EmailProvider([
                'name' => 'Brevo (.env)',
                'driver' => 'brevo',
                'secret' => $key,
                'from_email' => config('services.brevo.from_email'),
                'from_name' => config('services.brevo.from_name'),
                'is_active' => true,
            ]);
        }

        return EmailProvider::where('is_active', true)->first();
    }

    /**
     * The active provider that actually delivers mail (not the "log" driver), if any.
     */
    public function activeDeliveringProvider(): ?EmailProvider
    {
        $provider = $this->currentProvider();

        return $provider && $provider->driver !== 'log' ? $provider : null;
    }

    /**
     * Send a Blade mailable (OTP code, verify link) to one recipient. Uses the
     * active email provider from Admin → Email settings (e.g. Brevo) when one is
     * configured, otherwise Laravel's mailer. Unlike sendEvent, this THROWS when
     * delivery fails so callers can fail loudly.
     */
    public function sendMailable(string $eventKey, string $toEmail, Mailable $mailable): void
    {
        $provider = $this->activeDeliveringProvider();

        if (!$provider) {
            Mail::to($toEmail)->send($mailable);
            return;
        }

        $content = $mailable->content();
        $subject = (string) $mailable->envelope()->subject;
        $html = view($content->view, $content->with)->render();
        $text = $content->text ? view($content->text, $content->with)->render() : trim(strip_tags($html));

        $error = null;
        if (!$this->deliver($eventKey, $provider, $toEmail, $subject, $html, $text, $error)) {
            throw new RuntimeException('Email delivery failed via ' . $provider->name . ': ' . $error);
        }
    }

    /**
     * Send a test message through a specific provider and record the result on it.
     */
    public function sendTest(EmailProvider $provider, string $toEmail): array
    {
        $app = config('app.name');
        $error = null;

        $ok = $this->deliver(
            'test',
            $provider,
            $toEmail,
            "{$app} test email",
            '<p>This is a test email from <strong>' . e($app) . '</strong>. Your email provider <strong>' . e($provider->name) . '</strong> is working.</p>',
            "This is a test email from {$app}. Your email provider {$provider->name} is working.",
            $error,
        );

        $provider->update([
            'status' => $ok ? 'ok' : 'failed',
            'last_tested_at' => now(),
            'last_test_message' => $ok ? 'Test email sent to ' . $toEmail : $error,
        ]);

        return ['ok' => $ok, 'message' => $provider->last_test_message];
    }

    public function render(string $content, array $variables, bool $escape): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($variables, $escape) {
            $value = (string) ($variables[$m[1]] ?? '');
            return $escape ? e($value) : $value;
        }, $content);
    }

    private function deliver(string $eventKey, ?EmailProvider $provider, string $to, string $subject, string $html, string $text, ?string &$error = null): bool
    {
        // No active provider (or the "log" driver): write the email to the Laravel log instead of sending.
        if (!$provider || $provider->driver === 'log') {
            Log::info('Email (log driver)', ['event' => $eventKey, 'to' => $to, 'subject' => $subject, 'text' => $text]);
            $this->log($eventKey, $provider, $to, $subject, 'logged');
            return true;
        }

        try {
            match ($provider->driver) {
                'smtp', 'ses' => $this->sendSmtp($provider, $to, $subject, $html, $text),
                'brevo' => $this->sendBrevo($provider, $to, $subject, $html, $text),
                'sendgrid' => $this->sendSendgrid($provider, $to, $subject, $html, $text),
                'mailgun' => $this->sendMailgun($provider, $to, $subject, $html, $text),
            };
            $this->log($eventKey, $provider, $to, $subject, 'sent');
            return true;
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
            Log::error('Email delivery failed', ['event' => $eventKey, 'provider' => $provider->name, 'error' => $error]);
            $this->log($eventKey, $provider, $to, $subject, 'failed', $error);
            return false;
        }
    }

    private function sendSmtp(EmailProvider $p, string $to, string $subject, string $html, string $text): void
    {
        $isSes = $p->driver === 'ses';
        $encryption = $isSes ? 'tls' : ($p->encryption ?: 'tls');

        $mailer = Mail::build([
            'transport' => 'smtp',
            'host' => $isSes ? 'email-smtp.' . ($p->region ?: 'us-east-1') . '.amazonaws.com' : $p->host,
            'port' => $p->port ?: ($encryption === 'ssl' ? 465 : 587),
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'username' => $p->username,
            'password' => $p->secret,
            'timeout' => 15,
        ]);

        $mailer->send([], [], function (Message $m) use ($p, $to, $subject, $html, $text) {
            $m->from($p->from_email, $p->from_name)->to($to)->subject($subject);
            $m->html($html);
            $m->text($text);
        });
    }

    private function sendBrevo(EmailProvider $p, string $to, string $subject, string $html, string $text): void
    {
        $this->assertOk(Http::withHeaders(['api-key' => (string) $p->secret])->timeout(15)
            ->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => ['name' => $p->from_name, 'email' => $p->from_email],
                'to' => [['email' => $to]],
                'subject' => $subject,
                'htmlContent' => $html,
                'textContent' => $text,
            ]));
    }

    private function sendSendgrid(EmailProvider $p, string $to, string $subject, string $html, string $text): void
    {
        $this->assertOk(Http::withToken((string) $p->secret)->timeout(15)
            ->post('https://api.sendgrid.com/v3/mail/send', [
                'personalizations' => [['to' => [['email' => $to]]]],
                'from' => ['email' => $p->from_email, 'name' => $p->from_name],
                'subject' => $subject,
                'content' => [
                    ['type' => 'text/plain', 'value' => $text],
                    ['type' => 'text/html', 'value' => $html],
                ],
            ]));
    }

    private function sendMailgun(EmailProvider $p, string $to, string $subject, string $html, string $text): void
    {
        $base = $p->region === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';

        $this->assertOk(Http::withBasicAuth('api', (string) $p->secret)->asForm()->timeout(15)
            ->post("{$base}/v3/{$p->host}/messages", [
                'from' => "{$p->from_name} <{$p->from_email}>",
                'to' => $to,
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
            ]));
    }

    private function assertOk(Response $response): void
    {
        if ($response->failed()) {
            throw new RuntimeException('Provider responded HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }
    }

    private function log(string $eventKey, ?EmailProvider $provider, string $to, ?string $subject, string $status, ?string $error = null): void
    {
        EmailLog::create([
            'event_key' => $eventKey,
            'email_provider_id' => $provider?->id,
            'provider_name' => $provider?->name,
            'to_email' => $to,
            'subject' => $subject,
            'status' => $status,
            'error' => $error,
        ]);
    }
}

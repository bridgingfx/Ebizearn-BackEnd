<?php

namespace App\Services\Email;

use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;

/**
 * Builds and sends marketing campaigns through the active email provider
 * (Brevo, SMTP, …). Sending happens in small batches so it works on shared
 * hosting without a queue worker: each call sends the next batch after the
 * `last_user_id` cursor.
 */
class EmailCampaignService
{
    public const BATCH_SIZE = 25;

    public function __construct(private EmailService $emails)
    {
    }

    /**
     * Active, verified, subscribed users in the audience.
     */
    public function audienceQuery(string $audience): Builder
    {
        $roles = match ($audience) {
            'contributors' => ['contributor'],
            'businesses' => ['business'],
            default => ['contributor', 'business'],
        };

        return User::query()
            ->whereIn('role', $roles)
            ->where('status', 'active')
            ->whereNotNull('email_verified_at')
            ->whereNull('marketing_unsubscribed_at');
    }

    /**
     * Send the next batch. Returns the updated campaign.
     */
    public function sendBatch(EmailCampaign $campaign): EmailCampaign
    {
        if (in_array($campaign->status, ['sent', 'cancelled'], true)) {
            return $campaign;
        }

        if ($campaign->status === 'draft') {
            $campaign->update([
                'status' => 'sending',
                'started_at' => now(),
                'total_recipients' => $this->audienceQuery($campaign->audience)->count(),
            ]);
        }

        $users = $this->audienceQuery($campaign->audience)
            ->where('id', '>', $campaign->last_user_id)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get(['id', 'name', 'email']);

        $sent = 0;
        $failed = 0;
        $lastError = $campaign->last_error;

        foreach ($users as $user) {
            [$subject, $html, $text] = $this->render($campaign, $user);
            $error = null;

            if ($this->emails->sendRaw('campaign_' . $campaign->id, $user->email, $subject, $html, $text, $error)) {
                $sent++;
            } else {
                $failed++;
                $lastError = $error;
            }

            $campaign->last_user_id = $user->id;
        }

        $campaign->sent_count += $sent;
        $campaign->failed_count += $failed;
        $campaign->last_error = $lastError ? mb_substr($lastError, 0, 500) : null;

        if ($users->count() < self::BATCH_SIZE) {
            $campaign->status = 'sent';
            $campaign->completed_at = now();
        }

        $campaign->save();

        return $campaign;
    }

    /**
     * Send the campaign to one address (preview) without touching its counters.
     */
    public function sendTest(EmailCampaign $campaign, string $toEmail, string $name, ?string &$error = null): bool
    {
        $user = new User(['name' => $name, 'email' => $toEmail]);
        [$subject, $html, $text] = $this->render($campaign, $user);

        return $this->emails->sendRaw('campaign_test', $toEmail, '[Test] ' . $subject, $html, $text, $error);
    }

    /**
     * @return array{0: string, 1: string, 2: string} subject, html, text
     */
    public function render(EmailCampaign $campaign, User $user): array
    {
        $firstName = trim(explode(' ', (string) $user->name)[0] ?? '') ?: 'there';
        $vars = ['user_name' => $firstName, 'app_name' => (string) config('app.name')];
        $fill = fn (string $s) => preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => $vars[$m[1]] ?? '', $s);

        $subject = $fill($campaign->subject);
        $heading = $campaign->heading ? $fill($campaign->heading) : null;
        $body = $fill($campaign->body);

        $unsubscribe = $user->id
            ? rtrim((string) config('app.url'), '/') . URL::signedRoute('email.unsubscribe', ['user' => $user->id], null, false)
            : rtrim((string) config('platform.frontendUrl'), '/');

        $paragraphs = collect(preg_split('/\n{2,}/', trim($body)))
            ->map(fn ($p) => '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">' . nl2br(e($p)) . '</p>')
            ->implode('');

        $button = '';
        if ($campaign->button_label && $campaign->button_url) {
            $button = '<p style="margin:22px 0 6px;"><a href="' . e($campaign->button_url) . '" style="display:inline-block;padding:12px 26px;border-radius:12px;background:#168BFF;background-image:linear-gradient(90deg,#168BFF,#7257FF);color:#ffffff;font-weight:700;font-size:15px;text-decoration:none;">' . e($fill($campaign->button_label)) . '</a></p>';
        }

        $app = e((string) config('app.name'));
        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1F5F9;padding:28px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;">'
            . '<tr><td style="background:#07182F;padding:20px 28px;color:#ffffff;font-size:20px;font-weight:800;">' . $app . '</td></tr>'
            . '<tr><td style="padding:28px;">'
            . ($heading ? '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#07182F;">' . e($heading) . '</h1>' : '')
            . $paragraphs . $button
            . '</td></tr>'
            . '<tr><td style="padding:18px 28px;border-top:1px solid #E2E8F0;font-size:12px;line-height:1.5;color:#94A3B8;">'
            . 'You are receiving this because you have an ' . $app . ' account. '
            . '<a href="' . e($unsubscribe) . '" style="color:#64748B;">Unsubscribe from marketing emails</a>'
            . '</td></tr></table></td></tr></table></body></html>';

        $text = ($heading ? $heading . "\n\n" : '') . $body
            . ($button ? "\n\n" . $fill((string) $campaign->button_label) . ': ' . $campaign->button_url : '')
            . "\n\n---\nUnsubscribe from marketing emails: " . $unsubscribe;

        return [$subject, $html, $text];
    }
}

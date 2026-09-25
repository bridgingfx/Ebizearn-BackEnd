<?php

namespace App\Services\Email;

use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
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

        // A custom template chosen as the campaign design.
        $template = $campaign->template_key ? EmailTemplate::where('event_key', $campaign->template_key)->first() : null;
        if ($template) {
            $vars = array_merge(EmailLayout::variables(), [
                'app_name' => (string) config('app.name'),
                'support_email' => (string) config('platform.supportEmail'),
                'login_url' => rtrim((string) config('platform.frontendUrl'), '/') . '/login',
                'user_name' => $firstName,
                'unsubscribe_url' => $unsubscribe,
            ]);
            $html = $this->emails->render($template->html_body, $vars, true);
            $text = $this->emails->render($template->text_body, $vars, false);

            // Marketing email must always carry an unsubscribe link.
            if (!str_contains($template->html_body, '{{unsubscribe_url}}')) {
                $link = '<p style="margin:0;padding:16px;text-align:center;font-family:Arial,sans-serif;font-size:12px;color:#98A2B3">'
                    . '<a href="' . e($unsubscribe) . '" style="color:#667085">Unsubscribe from marketing emails</a></p>';
                $html = str_contains($html, '</body>') ? str_replace('</body>', $link . '</body>', $html) : $html . $link;
            }
            if (!str_contains($template->text_body, '{{unsubscribe_url}}')) {
                $text .= "\n\n---\nUnsubscribe from marketing emails: " . $unsubscribe;
            }

            return [$subject, $html, $text];
        }

        $paragraphs = collect(preg_split('/\n{2,}/', trim($body)))
            ->map(fn ($p) => nl2br(e($p)))
            ->all();

        $button = $campaign->button_label && $campaign->button_url
            ? [e($fill($campaign->button_label)), e($campaign->button_url)]
            : null;

        // Same branded layout as every other eBizEarn email.
        $layout = array_merge(EmailLayout::variables(), [
            'app_name' => e((string) config('app.name')),
            'support_email' => e((string) config('platform.supportEmail')),
            'eyebrow' => 'News from eBizEarn',
            'title' => e($heading ?: $subject),
            'paragraphs' => $paragraphs,
            'unsubscribe_url' => e($unsubscribe),
        ]);
        if ($button) {
            $layout['button'] = $button;
        }

        $html = EmailLayout::render($layout);
        $text = ($heading ? $heading . "\n\n" : '') . $body
            . ($button ? "\n\n" . $fill((string) $campaign->button_label) . ': ' . $campaign->button_url : '')
            . "\n\n---\nUnsubscribe from marketing emails: " . $unsubscribe;

        return [$subject, $html, $text];
    }
}

<?php

namespace App\Services\Email;

/**
 * Default transactional email templates, seeded by the email_templates migration
 * and restorable from the Super Admin template editor.
 */
class EmailTemplateDefaults
{
    private const COMMON = ['user_name', 'app_name', 'support_email'];

    public static function all(): array
    {
        return [
            self::make('welcome_contributor', 'Welcome (Contributor)', 'Welcome to {{app_name}}, {{user_name}}!',
                ['Welcome aboard, {{user_name}}!', 'Your contributor account is ready. Browse verified tasks, submit proof, and get paid.'],
                ['login_url'], 'Open your dashboard', '{{login_url}}'),
            self::make('welcome_business', 'Welcome (Business)', 'Welcome to {{app_name}}, {{user_name}}!',
                ['Welcome, {{user_name}}!', 'Your business account is ready. Create your first campaign and reach verified contributors.'],
                ['login_url'], 'Open your dashboard', '{{login_url}}'),
            self::make('verify_email', 'Verify Email', 'Verify your email address',
                ['Confirm your email', 'Hi {{user_name}}, please confirm your email address to secure your {{app_name}} account.'],
                ['verification_url'], 'Verify email', '{{verification_url}}'),
            self::make('forgot_password', 'Forgot Password', 'Reset your {{app_name}} password',
                ['Reset your password', 'Hi {{user_name}}, we received a request to reset your password. This link expires in 60 minutes. If you did not request it, you can ignore this email.'],
                ['reset_url'], 'Reset password', '{{reset_url}}'),
            self::make('password_changed', 'Password Changed', 'Your {{app_name}} password was changed',
                ['Password changed', 'Hi {{user_name}}, your password was just changed. If this was not you, contact {{support_email}} immediately.'],
                [], null, null),
            self::make('kyc_submitted', 'KYC Submitted', 'We received your identity documents',
                ['Documents received', 'Hi {{user_name}}, your identity verification is under review. We will email you once it is complete.'],
                [], null, null),
            self::make('kyc_approved', 'KYC Approved', 'Your identity is verified',
                ['You are verified', 'Hi {{user_name}}, your identity verification was approved. You can now withdraw your earnings.'],
                [], null, null),
            self::make('kyc_rejected', 'KYC Rejected', 'Identity verification needs attention',
                ['Verification not approved', 'Hi {{user_name}}, we could not approve your identity documents. Please review and resubmit, or contact {{support_email}}.'],
                ['reason'], null, null),
            self::make('withdrawal_requested', 'Withdrawal Requested', 'Withdrawal request received',
                ['Withdrawal requested', 'Hi {{user_name}}, we received your withdrawal request for {{amount}}. It will be reviewed shortly.'],
                ['amount'], null, null),
            self::make('withdrawal_paid', 'Withdrawal Paid', 'Your withdrawal has been paid',
                ['Withdrawal paid', 'Hi {{user_name}}, your withdrawal of {{amount}} has been processed.'],
                ['amount'], null, null),
            self::make('task_approved', 'Task Approved', 'Your task submission was approved',
                ['Submission approved', 'Hi {{user_name}}, your submission for "{{task_title}}" was approved and {{amount}} was added to your wallet.'],
                ['task_title', 'amount'], null, null),
            self::make('task_rejected', 'Task Rejected', 'Your task submission was rejected',
                ['Submission rejected', 'Hi {{user_name}}, your submission for "{{task_title}}" was not approved. Reason: {{reason}}'],
                ['task_title', 'reason'], null, null),
        ];
    }

    public static function find(string $key): ?array
    {
        foreach (self::all() as $template) {
            if ($template['event_key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    private static function make(string $key, string $name, string $subject, array $copy, array $extraVars, ?string $button, ?string $buttonUrl): array
    {
        [$heading, $body] = $copy;

        $buttonHtml = $button
            ? '<p style="margin:24px 0"><a href="' . $buttonUrl . '" style="background:#168BFF;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 24px;border-radius:10px;display:inline-block">' . $button . '</a></p>'
            . '<p style="font-size:12px;color:#6b7280">If the button does not work, copy this link:<br>' . $buttonUrl . '</p>'
            : '';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:24px">'
            . '<div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;padding:32px;color:#111827">'
            . '<p style="font-size:18px;font-weight:bold;color:#07182F;margin:0 0 16px">{{app_name}}</p>'
            . '<h1 style="font-size:20px;margin:0 0 12px">' . $heading . '</h1>'
            . '<p style="font-size:14px;line-height:1.6;color:#374151;margin:0">' . $body . '</p>'
            . $buttonHtml
            . '<p style="font-size:12px;color:#9ca3af;margin:24px 0 0">Need help? {{support_email}}</p>'
            . '</div></div>';

        $text = $heading . "\n\n" . $body . ($button ? "\n\n{$button}: {$buttonUrl}" : '') . "\n\nNeed help? {{support_email}}";

        return [
            'event_key' => $key,
            'name' => $name,
            'subject' => $subject,
            'html_body' => $html,
            'text_body' => $text,
            'variables' => array_merge(self::COMMON, $extraVars),
        ];
    }
}

<?php

namespace App\Services\Email;

/**
 * Default transactional email templates, seeded by the email_templates migration
 * and restorable from the Super Admin template editor. All of them use the
 * shared branded layout (EmailLayout): logo header, hero, details, footer.
 */
class EmailTemplateDefaults
{
    /** Values every template can use (filled by EmailService::sendEvent). */
    public const COMMON = ['user_name', 'app_name', 'support_email', 'logo_url', 'app_url', 'year'];

    public static function all(): array
    {
        return [
            self::make('welcome_contributor', 'Welcome (Contributor)', 'Welcome to {{app_name}}, {{user_name}}!', ['login_url'], [
                'preheader' => 'Your contributor account is ready — start earning from verified tasks today.',
                'eyebrow' => 'Welcome aboard',
                'title' => 'Your account is ready, {{user_name}}',
                'subtitle' => 'Complete simple tasks from real brands and get paid in cash.',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => [
                    'Thanks for joining {{app_name}}. Here is how to get your first reward:',
                    '<strong>1. Complete your profile</strong> so brands can match you with the right tasks.<br><strong>2. Pick a verified task</strong> — every campaign comes from a reviewed business.<br><strong>3. Submit your proof</strong> from your phone and get paid once it is approved.',
                ],
                'button' => ['Open your dashboard', '{{login_url}}'],
                'note' => 'It is free forever — we never ask you for fees or deposits.',
            ]),
            self::make('welcome_business', 'Welcome (Business)', 'Welcome to {{app_name}}, {{user_name}}!', ['login_url'], [
                'preheader' => 'Your business workspace is ready — launch your first campaign.',
                'eyebrow' => 'Business workspace',
                'title' => 'Welcome to {{app_name}}, {{user_name}}',
                'subtitle' => 'Reach thousands of verified people and pay only for approved work.',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => [
                    'Your business account is live. Getting started takes a few minutes:',
                    '<strong>1. Create a campaign</strong> and choose the tasks you need.<br><strong>2. Fund it</strong> — your budget stays in escrow until work is approved.<br><strong>3. Review proof</strong> from contributors and approve the work you are happy with.',
                ],
                'button' => ['Create your first campaign', '{{login_url}}'],
            ]),
            self::make('verify_email', 'Verify Email', 'Verify your email address', ['verification_url'], [
                'preheader' => 'Confirm your email to secure your account.',
                'eyebrow' => 'Account security',
                'title' => 'Confirm your email address',
                'subtitle' => 'One quick step to protect your {{app_name}} account.',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => ['Please confirm that this is your email address. This keeps your account and your earnings safe.'],
                'button' => ['Verify email', '{{verification_url}}'],
                'note' => 'This link expires in 24 hours. If you did not create an account, you can ignore this email.',
            ]),
            self::make('forgot_password', 'Forgot Password', 'Reset your {{app_name}} password', ['reset_url'], [
                'preheader' => 'Use this link to choose a new password.',
                'eyebrow' => 'Password reset',
                'title' => 'Reset your password',
                'subtitle' => 'We received a request to reset the password for your account.',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => ['Click the button below to choose a new password.'],
                'button' => ['Choose a new password', '{{reset_url}}'],
                'note' => 'This link expires in 60 minutes. If you did not ask for a reset, ignore this email — your password stays the same.',
            ]),
            self::make('password_changed', 'Password Changed', 'Your {{app_name}} password was changed', [], [
                'tone' => 'warning',
                'preheader' => 'Your password was just changed.',
                'eyebrow' => 'Security alert',
                'title' => 'Your password was changed',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => [
                    'The password for your {{app_name}} account was just changed, and other devices were signed out.',
                    'If this was you, there is nothing else to do.',
                ],
                'note' => 'Not you? Contact <strong>{{support_email}}</strong> right away so we can secure your account.',
            ]),
            self::make('kyc_submitted', 'KYC Submitted', 'We received your identity documents', [], [
                'preheader' => 'Your identity verification is under review.',
                'eyebrow' => 'Identity verification',
                'title' => 'Documents received',
                'subtitle' => 'Our team is reviewing your identity documents.',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => [
                    'Thanks for submitting your documents. Reviews usually take less than 24 hours.',
                    'We will email you as soon as it is complete — no need to do anything else.',
                ],
            ]),
            self::make('kyc_approved', 'KYC Approved', 'Your identity is verified', ['login_url'], [
                'tone' => 'success',
                'preheader' => 'You are verified — withdrawals are unlocked.',
                'eyebrow' => 'Verified',
                'title' => 'You are verified!',
                'subtitle' => 'Withdrawals are now unlocked on your account.',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => ['Great news — your identity verification was approved. You can now withdraw your earnings to your bank.'],
                'button' => ['Go to my wallet', '{{login_url}}'],
            ]),
            self::make('kyc_rejected', 'KYC Rejected', 'Identity verification needs attention', ['reason', 'login_url'], [
                'tone' => 'danger',
                'preheader' => 'We could not approve your identity documents.',
                'eyebrow' => 'Action needed',
                'title' => 'Verification not approved',
                'subtitle' => 'Please review the reason below and submit again.',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => ['We could not approve your identity documents this time.'],
                'details' => [['Reason', '{{reason}}']],
                'button' => ['Resubmit documents', '{{login_url}}'],
                'note' => 'Tip: use a clear photo of the whole document with all four corners visible.',
            ]),
            self::make('withdrawal_requested', 'Withdrawal Requested', 'Withdrawal request received', ['amount'], [
                'preheader' => 'We received your withdrawal request.',
                'eyebrow' => 'Payout',
                'title' => 'Withdrawal request received',
                'subtitle' => 'Our finance team will review it shortly.',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => ['We received your withdrawal request. You will get another email once it has been paid.'],
                'details' => [['Amount', '{{amount}}'], ['Status', 'Under review']],
            ]),
            self::make('withdrawal_paid', 'Withdrawal Paid', 'Your withdrawal has been paid', ['amount'], [
                'tone' => 'success',
                'preheader' => 'Your money is on its way.',
                'eyebrow' => 'Payout sent',
                'title' => 'Your withdrawal has been paid',
                'subtitle' => 'The money is on its way to your account.',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => ['Good news — we processed your withdrawal. Depending on your bank it can take 1–3 business days to appear.'],
                'details' => [['Amount', '{{amount}}'], ['Status', 'Paid']],
            ]),
            self::make('task_approved', 'Task Approved', 'Your task submission was approved', ['task_title', 'amount', 'login_url'], [
                'tone' => 'success',
                'preheader' => 'Your proof was approved and your reward is in your wallet.',
                'eyebrow' => 'Task approved',
                'title' => 'Nice work, {{user_name}}!',
                'subtitle' => 'Your proof was approved and your reward was added to your wallet.',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => ['Your submission passed review. Keep it up — more verified tasks are waiting for you.'],
                'details' => [['Task', '{{task_title}}'], ['Reward', '{{amount}}']],
                'button' => ['Find more tasks', '{{login_url}}'],
            ]),
            self::make('task_rejected', 'Task Rejected', 'Your task submission was rejected', ['task_title', 'reason', 'login_url'], [
                'tone' => 'danger',
                'preheader' => 'Your submission was not approved this time.',
                'eyebrow' => 'Task not approved',
                'title' => 'Submission not approved',
                'subtitle' => 'Here is what the reviewer found.',
                'greeting' => 'Hi {{user_name}},',
                'paragraphs' => ['Unfortunately your proof for this task was not approved.'],
                'details' => [['Task', '{{task_title}}'], ['Reason', '{{reason}}']],
                'button' => ['Browse other tasks', '{{login_url}}'],
                'note' => 'Tip: read each task’s requirements carefully and make sure your screenshot shows everything asked for.',
            ]),
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

    /**
     * A starting point for a new custom template in the editor.
     */
    public static function blank(): array
    {
        $layout = [
            'eyebrow' => 'Announcement',
            'title' => 'Your headline here',
            'subtitle' => 'A short line that supports the headline.',
            'greeting' => 'Hi {{user_name}},',
            'paragraphs' => ['Write your message here. You can use {{user_name}} and the other variables listed above.'],
            'button' => ['Open eBizEarn', '{{app_url}}'],
        ];

        return ['html_body' => EmailLayout::render($layout), 'text_body' => EmailLayout::text($layout)];
    }

    private static function make(string $key, string $name, string $subject, array $extraVars, array $layout): array
    {
        return [
            'event_key' => $key,
            'name' => $name,
            'subject' => $subject,
            'html_body' => EmailLayout::render($layout),
            'text_body' => EmailLayout::text($layout),
            'variables' => array_values(array_unique(array_merge(self::COMMON, $extraVars))),
        ];
    }
}

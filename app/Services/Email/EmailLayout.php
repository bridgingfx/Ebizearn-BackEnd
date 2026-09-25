<?php

namespace App\Services\Email;

/**
 * The one branded email layout used by every eBizEarn email: logo header,
 * hero, content (paragraphs, detail rows, code, button) and footer.
 *
 * Table-based with inline styles so it renders the same in Gmail, Outlook,
 * Apple Mail and mobile clients. Brand colours: navy #07182F, blue #168BFF,
 * violet #7257FF.
 *
 * Values are inserted as given: database templates pass {{placeholders}}
 * (filled and escaped later by EmailService::render), Blade emails pass
 * already-escaped real values.
 */
class EmailLayout
{
    public const NAVY = '#07182F';
    public const BLUE = '#168BFF';
    public const VIOLET = '#7257FF';

    private const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    /** Hero accent per tone: [badge background, badge text]. */
    private const TONES = [
        'brand' => ['#168BFF', '#FFFFFF'],
        'success' => ['#12B76A', '#FFFFFF'],
        'warning' => ['#F79009', '#FFFFFF'],
        'danger' => ['#F04438', '#FFFFFF'],
    ];

    /**
     * Shared values every email can use: {{logo_url}}, {{app_url}}, {{year}}…
     */
    public static function variables(): array
    {
        $site = rtrim((string) config('platform.frontendUrl'), '/');

        return [
            'logo_url' => $site . '/assets/email-logo.png',
            'app_url' => $site,
            'help_url' => $site . '/faq',
            'terms_url' => $site . '/terms',
            'privacy_url' => $site . '/privacy',
            'year' => date('Y'),
        ];
    }

    /**
     * @param array{
     *   preheader?: string, eyebrow?: string, title: string, subtitle?: string, tone?: string,
     *   greeting?: string, paragraphs?: string[], details?: array<int, array{0: string, 1: string}>,
     *   code?: string, button?: array{0: string, 1: string}, note?: string,
     *   logo_url?: string, app_url?: string, help_url?: string, terms_url?: string, privacy_url?: string,
     *   year?: string, app_name?: string, support_email?: string
     * } $o
     */
    public static function render(array $o): string
    {
        $v = fn (string $key, string $placeholder) => $o[$key] ?? '{{' . $placeholder . '}}';
        $logo = $v('logo_url', 'logo_url');
        $appUrl = $v('app_url', 'app_url');
        $appName = $v('app_name', 'app_name');
        $support = $v('support_email', 'support_email');
        [$badgeBg, $badgeFg] = self::TONES[$o['tone'] ?? 'brand'] ?? self::TONES['brand'];
        $f = self::FONT;

        $preheader = isset($o['preheader'])
            ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all">' . $o['preheader'] . '</div>'
            : '';

        $eyebrow = isset($o['eyebrow'])
            ? '<span style="display:inline-block;padding:5px 12px;border-radius:999px;background:' . $badgeBg . ';color:' . $badgeFg . ';font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase">' . $o['eyebrow'] . '</span>'
            : '';

        $subtitle = isset($o['subtitle'])
            ? '<p style="margin:10px 0 0;font-size:15px;line-height:1.6;color:#B8C4D6">' . $o['subtitle'] . '</p>'
            : '';

        $content = '';
        if (isset($o['greeting'])) {
            $content .= '<p style="margin:0 0 16px;font-size:16px;font-weight:600;color:' . self::NAVY . '">' . $o['greeting'] . '</p>';
        }
        foreach ($o['paragraphs'] ?? [] as $p) {
            $content .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#344054">' . $p . '</p>';
        }

        if (!empty($o['code'])) {
            $content .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 20px"><tr><td align="center" style="padding:22px 12px;background:#F4F8FF;border:1px dashed #B2CCFF;border-radius:14px">'
                . '<div style="font-family:\'SF Mono\',Menlo,Consolas,monospace;font-size:34px;font-weight:700;letter-spacing:10px;color:' . self::NAVY . '">' . $o['code'] . '</div>'
                . '</td></tr></table>';
        }

        if (!empty($o['details'])) {
            $rows = '';
            foreach ($o['details'] as $i => [$label, $value]) {
                $border = $i > 0 ? 'border-top:1px solid #EAECF0;' : '';
                $rows .= '<tr>'
                    . '<td style="' . $border . 'padding:12px 16px;font-size:13px;color:#667085">' . $label . '</td>'
                    . '<td align="right" style="' . $border . 'padding:12px 16px;font-size:14px;font-weight:600;color:' . self::NAVY . '">' . $value . '</td>'
                    . '</tr>';
            }
            $content .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 20px;background:#F9FAFB;border:1px solid #EAECF0;border-radius:12px;border-collapse:separate">' . $rows . '</table>';
        }

        if (!empty($o['button'])) {
            [$label, $url] = $o['button'];
            $content .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 22px"><tr>'
                . '<td bgcolor="' . self::BLUE . '" style="border-radius:12px;background:' . self::BLUE . ';background-image:linear-gradient(90deg,' . self::BLUE . ',' . self::VIOLET . ')">'
                . '<a href="' . $url . '" target="_blank" style="display:inline-block;padding:14px 30px;font-family:' . $f . ';font-size:15px;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:12px">' . $label . ' &rarr;</a>'
                . '</td></tr></table>'
                . '<p style="margin:0 0 16px;font-size:12px;line-height:1.6;color:#98A2B3">Button not working? Copy this link into your browser:<br><a href="' . $url . '" style="color:' . self::BLUE . ';word-break:break-all">' . $url . '</a></p>';
        }

        if (isset($o['note'])) {
            $content .= '<p style="margin:4px 0 0;padding:12px 14px;background:#FFFAEB;border-radius:10px;font-size:13px;line-height:1.6;color:#93370D">' . $o['note'] . '</p>';
        }

        $help = $v('help_url', 'help_url');
        $terms = $v('terms_url', 'terms_url');
        $privacy = $v('privacy_url', 'privacy_url');
        $year = $v('year', 'year');

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><title>' . strip_tags($o['title']) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#EEF2F7;font-family:' . $f . ';-webkit-font-smoothing:antialiased">'
            . $preheader
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#EEF2F7" style="background:#EEF2F7"><tr><td align="center" style="padding:32px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'

            // Header: logo
            . '<tr><td bgcolor="#FFFFFF" style="background:#FFFFFF;border-radius:20px 20px 0 0;padding:22px 32px;border-bottom:3px solid ' . self::BLUE . '">'
            . '<a href="' . $appUrl . '" target="_blank" style="text-decoration:none"><img src="' . $logo . '" width="150" alt="' . $appName . '" style="display:block;width:150px;max-width:150px;height:auto;border:0"></a>'
            . '</td></tr>'

            // Hero
            . '<tr><td bgcolor="' . self::NAVY . '" style="background:' . self::NAVY . ';background-image:linear-gradient(135deg,#07182F 0%,#0B2A57 60%,#1B2F7A 100%);padding:36px 32px 34px">'
            . $eyebrow
            . '<h1 style="margin:' . ($eyebrow ? '16px' : '0') . ' 0 0;font-size:26px;line-height:1.3;font-weight:800;color:#FFFFFF;letter-spacing:-0.3px">' . $o['title'] . '</h1>'
            . $subtitle
            . '</td></tr>'

            // Content
            . '<tr><td bgcolor="#FFFFFF" style="background:#FFFFFF;padding:32px 32px 28px">' . $content . '</td></tr>'

            // Sign-off
            . '<tr><td bgcolor="#FFFFFF" style="background:#FFFFFF;padding:0 32px 28px;border-radius:0 0 20px 20px">'
            . '<p style="margin:0;padding-top:20px;border-top:1px solid #EAECF0;font-size:14px;line-height:1.6;color:#475467">Questions? Just reply to this email or write to <a href="mailto:' . $support . '" style="color:' . self::BLUE . ';text-decoration:none;font-weight:600">' . $support . '</a>.<br>&mdash; The ' . $appName . ' team</p>'
            . '</td></tr>'

            // Footer
            . '<tr><td align="center" style="padding:24px 16px 8px;font-size:12px;line-height:1.7;color:#98A2B3">'
            . '<a href="' . $help . '" style="color:#667085;text-decoration:none;margin:0 8px">Help Center</a>&middot;'
            . '<a href="' . $terms . '" style="color:#667085;text-decoration:none;margin:0 8px">Terms</a>&middot;'
            . '<a href="' . $privacy . '" style="color:#667085;text-decoration:none;margin:0 8px">Privacy</a>'
            . '<br>&copy; ' . $year . ' eBiz Network (ebizearn.com). All rights reserved.'
            . '<br>You are receiving this email because of your ' . $appName . ' account.'
            . (isset($o['unsubscribe_url']) ? '<br><a href="' . $o['unsubscribe_url'] . '" style="color:#667085;text-decoration:underline">Unsubscribe from marketing emails</a>' : '')
            . '</td></tr>'

            . '</table></td></tr></table></body></html>';

        // One block per line so the HTML is easy to read and edit in the template editor.
        return preg_replace('#>(?=<(?:/?(?:table|tr|body|head)\b|p\b|h1\b|div\b|meta\b|title\b))#', ">\n", $html);
    }

    /**
     * Plain-text version to go with render().
     */
    public static function text(array $o): string
    {
        $v = fn (string $key, string $placeholder) => $o[$key] ?? '{{' . $placeholder . '}}';
        $lines = [strip_tags($o['title'])];
        if (isset($o['subtitle'])) {
            $lines[] = strip_tags($o['subtitle']);
        }
        $lines[] = '';
        if (isset($o['greeting'])) {
            $lines[] = strip_tags($o['greeting']);
            $lines[] = '';
        }
        foreach ($o['paragraphs'] ?? [] as $p) {
            $lines[] = html_entity_decode(strip_tags($p));
            $lines[] = '';
        }
        if (!empty($o['code'])) {
            $lines[] = 'Your code: ' . strip_tags($o['code']);
            $lines[] = '';
        }
        foreach ($o['details'] ?? [] as [$label, $value]) {
            $lines[] = strip_tags($label) . ': ' . strip_tags($value);
        }
        if (!empty($o['details'])) {
            $lines[] = '';
        }
        if (!empty($o['button'])) {
            $lines[] = strip_tags($o['button'][0]) . ': ' . $o['button'][1];
            $lines[] = '';
        }
        if (isset($o['note'])) {
            $lines[] = html_entity_decode(strip_tags($o['note']));
            $lines[] = '';
        }
        $lines[] = 'Questions? Write to ' . $v('support_email', 'support_email');
        $lines[] = '— The ' . $v('app_name', 'app_name') . ' team';

        return implode("\n", $lines);
    }
}

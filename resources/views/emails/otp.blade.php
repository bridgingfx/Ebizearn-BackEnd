<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your eBizEarn verification code</title>
</head>
<body style="margin:0;padding:0;background-color:#0B0F19;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0B0F19;padding:40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;">
                    {{-- Brand header --}}
                    <tr>
                        <td style="background-color:#0B0F19;padding:32px 40px;text-align:center;border-bottom:3px solid #D4AF37;">
                            <div style="font-size:28px;font-weight:800;letter-spacing:1px;color:#ffffff;">
                                eBiz<span style="color:#D4AF37;">Earn</span>
                            </div>
                            <div style="margin-top:6px;font-size:12px;letter-spacing:3px;text-transform:uppercase;color:#9aa3b2;">
                                Earn. Verify. Withdraw.
                            </div>
                        </td>
                    </tr>
                    {{-- Body --}}
                    <tr>
                        <td style="padding:40px;">
                            <h1 style="margin:0 0 16px;font-size:22px;color:#0B0F19;">Your verification code</h1>
                            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#3c4454;">
                                Hi {{ $userName }}, enter this 6-digit code to verify your email address
                                and activate your eBizEarn account:
                            </p>
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">
                                <tr>
                                    <td align="center" style="background-color:#0B0F19;border-radius:8px;padding:20px 40px;">
                                        <div style="font-size:36px;font-weight:800;letter-spacing:10px;color:#D4AF37;font-family:'Courier New',monospace;">{{ $code }}</div>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#3c4454;">
                                This code expires in <strong>{{ $ttlMinutes }} minutes</strong>. Never share it
                                with anyone — eBizEarn staff will never ask for it.
                            </p>
                            <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#8a93a5;">
                                Didn't create an eBizEarn account? You can safely ignore this email —
                                the address will stay unverified and no account activity is possible.
                            </p>
                        </td>
                    </tr>
                    {{-- Footer --}}
                    <tr>
                        <td style="padding:24px 40px;background-color:#f4f5f7;border-top:1px solid #e6e8ec;text-align:center;">
                            <p style="margin:0;font-size:12px;color:#8a93a5;">
                                © {{ date('Y') }} eBizEarn. All rights reserved.<br>
                                Need help? Contact <a href="mailto:support@ebizearn.com" style="color:#8a6d1f;">support@ebizearn.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

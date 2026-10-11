<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $variant === 'invite' ? 'Workspace invitation' : 'Reset your password' }}</title>
</head>
<body style="margin:0;padding:24px;background:#f6f8fc;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;border:1px solid #e2e8f0;">
        <tr>
            <td style="padding:32px;">
                @if ($variant === 'invite')
                    <h1 style="margin:0 0 12px;font-size:20px;">You've been invited</h1>
                    <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#475569;">
                        {{ $name ?: 'Hi' }}, you have been added to
                        <strong>{{ $tenantName ?: 'your workspace' }}</strong> on NoovaERP.
                        Set your password to sign in.
                    </p>
                @else
                    <h1 style="margin:0 0 12px;font-size:20px;">Reset your password</h1>
                    <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#475569;">
                        {{ $name ? $name . ',' : 'Hi,' }} we received a request to reset your NoovaERP password.
                        This link is valid for 7 days.
                    </p>
                @endif

                <p style="margin:24px 0;">
                    <a href="{{ $url }}"
                       style="display:inline-block;background:#2E8B74;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:10px 20px;border-radius:999px;">
                        {{ $variant === 'invite' ? 'Set my password' : 'Choose a new password' }}
                    </a>
                </p>

                <p style="margin:0 0 8px;font-size:12px;color:#64748b;">
                    If the button doesn't work, copy this link into your browser:
                </p>
                <p style="margin:0;font-size:12px;word-break:break-all;color:#2E8B74;">{{ $url }}</p>

                @if ($variant !== 'invite')
                    <p style="margin:24px 0 0;font-size:12px;color:#64748b;">
                        If you didn't request this, you can safely ignore this email.
                    </p>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
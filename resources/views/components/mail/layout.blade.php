<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f7f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f9;padding:32px 0">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%">
                <tr><td style="padding:0 8px 20px">
                    <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                        <td style="width:34px;height:34px;background:#4f46e5;border-radius:9px;text-align:center;vertical-align:middle;color:#fff;font-weight:700;font-size:16px">C</td>
                        <td style="padding-left:10px;font-weight:600;font-size:16px;color:#14161c">Cbox&nbsp;ID</td>
                    </tr></table>
                </td></tr>
                <tr><td style="background:#ffffff;border:1px solid #e4e7ec;border-radius:14px;padding:28px">
                    {{ $slot }}
                </td></tr>
                <tr><td style="padding:18px 8px;color:#8a909c;font-size:12px">
                    © {{ date('Y') }} Cbox · This is an automated message from your identity platform.
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>

@props(['brand' => \App\Mail\MailText::brand(), 'logo' => \App\Mail\MailText::logo()])
{{--
    The frame every hosted mail is drawn in. `lang` is the RECIPIENT's language: the
    template renders inside the send's `withLocale()`, so the app locale here is the one
    the send site chose for them. The mark and the footer carry the deployment's brand,
    not ours — a renamed install must not sign its mail with somebody else's name.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f7f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f9;padding:32px 0">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%">
                <tr><td style="padding:0 8px 20px">
                    {{-- The environment's uploaded logo, from this application's own URL — never a
                         remote one, which would be a read-receipt pixel. Without one, the monogram. --}}
                    @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $brand }}" height="34" style="display:block;height:34px;max-width:200px;border:0">
                    @else
                    <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                        <td style="width:34px;height:34px;background:#4f46e5;border-radius:9px;text-align:center;vertical-align:middle;color:#fff;font-weight:700;font-size:16px">{{ mb_strtoupper(mb_substr($brand, 0, 1)) }}</td>
                        <td style="padding-left:10px;font-weight:600;font-size:16px;color:#14161c">{{ $brand }}</td>
                    </tr></table>
                    @endif
                </td></tr>
                <tr><td style="background:#ffffff;border:1px solid #e4e7ec;border-radius:14px;padding:28px">
                    {{ $slot }}
                </td></tr>
                <tr><td style="padding:18px 8px;color:#8a909c;font-size:12px">
                    {{ __('mail.layout.footer', ['year' => date('Y'), 'brand' => $brand]) }}
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>

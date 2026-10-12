@props(['brand', 'theme', 'preheader' => null])
{{--
    The frame every hosted mail is drawn in — in the brand of whoever the reader was dealing
    with ({@see \App\Mail\MailBrands}): their logo or monogram, their colour on the monogram
    and the button, their name in the footer. `lang` is the RECIPIENT's language: the
    template renders inside the send's `withLocale()`.

    ACCESSIBILITY, for a medium with no stylesheet to lean on: real text throughout (never a
    picture of words), `alt` on the logo naming the brand, `role="presentation"` on every
    layout table so a screen reader does not announce a grid, and every colour pair at
    4.5:1 or better — the old footer grey was 3.1:1 on this background, and the fine print
    on the card 3.3:1. The brand's own colour is only ever used where it was checked:
    `onAccent` on the button and monogram, `accentText` for text on the card.

    `color-scheme: light`, declared: the card is drawn on explicit light colours, and a
    client that inverts a mail it believes is dark-capable turns a brand colour into its
    opposite. Clients that force dark mode anyway get a frame with no transparent gaps.

    The PREHEADER is the line an inbox previews beside the subject. Without one it lifts the
    first text of the body — which, for a one-time code, would put the code on the lock
    screen. It is hidden in the open mail and padded so the preview stops with it.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $brand }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-text-size-adjust:100%">
    @if ($preheader)
    <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f6f7f9;opacity:0">{{ $preheader }}{!! str_repeat('&#8199;&#65279;&#847; ', 40) !!}</div>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f9;padding:32px 0">
        <tr><td align="center" style="padding:0 12px">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;width:100%">
                <tr><td style="padding:0 4px 20px">
                    {{-- The uploaded logo, from this application's own URL — never a remote one,
                         which would be a read-receipt pixel. Without one, the monogram. --}}
                    @if ($theme->logo)
                    <img src="{{ $theme->logo }}" alt="{{ $brand }}" height="36" style="display:block;height:36px;width:auto;max-width:220px;border:0;outline:none;text-decoration:none;color:#14161c;font-weight:600;font-size:16px">
                    @else
                    <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                        <td aria-hidden="true" style="width:36px;height:36px;background:{{ $theme->accent }};border-radius:9px;text-align:center;vertical-align:middle;color:{{ $theme->onAccent }};font-weight:700;font-size:17px">{{ $theme->initial() }}</td>
                        <td style="padding-left:10px;font-weight:600;font-size:16px;color:#14161c">{{ $brand }}</td>
                    </tr></table>
                    @endif
                </td></tr>
                <tr><td style="background:#ffffff;border:1px solid #e4e7ec;border-radius:14px;padding:28px 24px">
                    {{ $slot }}
                </td></tr>
                <tr><td style="padding:18px 4px;color:#5b616e;font-size:12px;line-height:1.6">
                    {{ __('mail.layout.footer', ['year' => date('Y'), 'brand' => $brand]) }}
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>

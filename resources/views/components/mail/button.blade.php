@props(['href', 'theme'])
{{--
    The one action a mail asks for, as a real link styled as a button: the brand's colour,
    with whichever of white or near-black text is readable on it ({@see \App\Mail\MailBrand}).
    A table cell carries the fill too, so clients that ignore padding on links (Outlook)
    still draw a button rather than a coloured word.
--}}
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:{{ $theme->accent }};border-radius:10px">
    <a href="{{ $href }}" style="display:inline-block;background:{{ $theme->accent }};color:{{ $theme->onAccent }};text-decoration:none;font-weight:600;font-size:15px;line-height:1.2;padding:13px 22px;border-radius:10px">{{ $slot }}</a>
</td></tr></table>

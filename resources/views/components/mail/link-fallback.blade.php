@props(['href', 'theme'])
{{-- The button's address written out, for a client that will not draw the button. --}}
<p style="margin:22px 0 0;color:#5b616e;font-size:13px;line-height:1.6;word-break:break-all">
    {{ __('mail.common.paste_link') }}<br><a href="{{ $href }}" style="color:{{ $theme->accentText }}">{{ $href }}</a>
</p>

<x-mail.layout :brand="$brand">
    <h1 style="margin:0 0 12px;font-size:20px;color:#14161c">{{ __('mail.portal_link.heading', ['organization' => $organization]) }}</h1>
    <p style="margin:0 0 12px;color:#5b616e;font-size:15px;line-height:1.6">
        {{ \App\Mail\MailText::html('mail.portal_link.lead', ['organization' => $organization], ['brand' => $brand]) }}
    </p>
    <ul style="margin:0 0 20px;padding-left:20px;color:#14161c;font-size:15px;line-height:1.7">
        @foreach ($tasks as $task)
            <li>{{ $task }}</li>
        @endforeach
    </ul>
    <table role="presentation" cellpadding="0" cellspacing="0"><tr><td>
        <a href="{{ $url }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 22px;border-radius:10px">{{ __('mail.portal_link.button') }}</a>
    </td></tr></table>
    <p style="margin:20px 0 0;color:#5b616e;font-size:13px;line-height:1.6">
        {{ __('mail.portal_link.expires', ['date' => $expiresOn]) }}
    </p>
    <p style="margin:12px 0 0;color:#8a909c;font-size:12px;line-height:1.6;word-break:break-all">
        {{ __('mail.common.paste_link') }}<br>{{ $url }}
    </p>
</x-mail.layout>

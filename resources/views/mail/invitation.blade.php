<x-mail.layout>
    <h1 style="margin:0 0 12px;font-size:20px;color:#14161c">Join {{ $organization }}</h1>
    <p style="margin:0 0 20px;color:#5b616e;font-size:15px;line-height:1.6">
        <b>{{ $inviter }}</b> invited you to join <b>{{ $organization }}</b>@if ($role) as <b>{{ $role }}</b>@endif.
        @if ($app)
            Accept to sign in to <b>{{ $app }}</b> with your {{ $organization }} account.
        @else
            Accept to set up your account and sign in.
        @endif
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0"><tr><td>
        <a href="{{ $url }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 22px;border-radius:10px">Review invitation</a>
    </td></tr></table>
    <p style="margin:22px 0 0;color:#8a909c;font-size:12px;line-height:1.6">
        The link opens a page where you confirm — nothing happens until you do. It expires in 7 days.
        If you weren't expecting this, you can ignore it.
    </p>
    <p style="margin:12px 0 0;color:#8a909c;font-size:12px;line-height:1.6;word-break:break-all">
        Or paste this link into your browser:<br>{{ $url }}
    </p>
</x-mail.layout>

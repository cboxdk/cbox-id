<x-mail.layout>
    <h1 style="margin:0 0 12px;font-size:20px;color:#14161c">Reset your password</h1>
    <p style="margin:0 0 20px;color:#5b616e;font-size:15px;line-height:1.6">
        We received a request to reset your Cbox ID password. Click the button below to choose a
        new one. This link is single-use and expires in 60 minutes. If you didn't request it, you
        can safely ignore this email — your password won't change.
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0"><tr><td>
        <a href="{{ $url }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 22px;border-radius:10px">Reset password</a>
    </td></tr></table>
    <p style="margin:22px 0 0;color:#8a909c;font-size:12px;line-height:1.6;word-break:break-all">
        Or paste this link into your browser:<br>{{ $url }}
    </p>
</x-mail.layout>

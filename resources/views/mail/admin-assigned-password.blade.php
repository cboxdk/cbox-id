<x-mail.layout>
    <h1 style="margin:0 0 12px;font-size:20px;color:#14161c">Your password has been reset</h1>
    <p style="margin:0 0 20px;color:#5b616e;font-size:15px;line-height:1.6">
        An administrator set a new password on your account. Sign in with it below.
        @if ($temporary)
            You'll be asked to choose your own password straight away.
        @endif
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px"><tr><td style="background:#f4f5f8;border:1px solid #e4e6ec;border-radius:10px;padding:14px 18px">
        <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:16px;color:#14161c;letter-spacing:.02em">{{ $password }}</span>
    </td></tr></table>
    @if ($expiresAt)
        <p style="margin:0 0 20px;color:#5b616e;font-size:15px;line-height:1.6">
            This password stops working on {{ $expiresAt }}, so please sign in before then.
        </p>
    @endif
    <p style="margin:22px 0 0;color:#8a909c;font-size:12px;line-height:1.6">
        If you weren't expecting this, contact your administrator — someone with access to your
        organisation's console made this change, and it is recorded on the audit trail.
    </p>
</x-mail.layout>

<x-mail.layout :brand="$brand" :theme="$theme">
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#14161c">{{ __('mail.admin_assigned_password.heading') }}</h1>
    <p style="margin:0 0 20px;color:#3f4450;font-size:15px;line-height:1.6">
        {{ __('mail.admin_assigned_password.body') }}
        @if ($temporary)
            {{ __('mail.admin_assigned_password.temporary') }}
        @endif
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px"><tr><td style="background:#f4f5f8;border:1px solid #e4e6ec;border-radius:10px;padding:14px 18px">
        <span style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:17px;color:#14161c;letter-spacing:.02em;word-break:break-all;-webkit-user-select:all;user-select:all">{{ $password }}</span>
    </td></tr></table>
    @if ($expiresOn)
        <p style="margin:0 0 20px;color:#3f4450;font-size:15px;line-height:1.6">
            {{ __('mail.admin_assigned_password.expires', ['date' => $expiresOn]) }}
        </p>
    @endif
    <p style="margin:22px 0 0;color:#5b616e;font-size:13px;line-height:1.6">
        {{ __('mail.admin_assigned_password.not_expected') }}
    </p>
</x-mail.layout>

<x-mail.layout :brand="$brand" :theme="$theme">
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#14161c">{{ __('mail.organization_invite.heading', ['organization' => $organization, 'brand' => $brand]) }}</h1>
    <p style="margin:0 0 20px;color:#3f4450;font-size:15px;line-height:1.6">
        @if ($roleName)
            {{ \App\Mail\MailText::html('mail.organization_invite.invited_as', ['inviter' => $inviter, 'organization' => $organization, 'role' => $roleName], ['brand' => $brand]) }}
        @else
            {{ \App\Mail\MailText::html('mail.organization_invite.invited', ['inviter' => $inviter, 'organization' => $organization], ['brand' => $brand]) }}
        @endif
    </p>
    <x-mail.button :href="$url" :theme="$theme">{{ __('mail.organization_invite.button') }}</x-mail.button>
    <p style="margin:22px 0 0;color:#5b616e;font-size:13px;line-height:1.6">
        {{ __('mail.organization_invite.note') }}
    </p>
    <x-mail.link-fallback :href="$url" :theme="$theme" />
</x-mail.layout>

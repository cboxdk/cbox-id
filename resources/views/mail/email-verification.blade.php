<x-mail.layout :brand="$brand" :theme="$theme">
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#14161c">{{ __('mail.email_verification.heading') }}</h1>
    <p style="margin:0 0 20px;color:#3f4450;font-size:15px;line-height:1.6">
        {{ __('mail.email_verification.body', ['brand' => $brand]) }}
    </p>
    <x-mail.button :href="$url" :theme="$theme">{{ __('mail.email_verification.button') }}</x-mail.button>
    <p style="margin:22px 0 0;color:#5b616e;font-size:13px;line-height:1.6">
        {{ __('mail.email_verification.not_you', ['brand' => $brand]) }}
    </p>
    <x-mail.link-fallback :href="$url" :theme="$theme" />
</x-mail.layout>

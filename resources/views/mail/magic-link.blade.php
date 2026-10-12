<x-mail.layout :brand="$brand" :theme="$theme">
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#14161c">{{ __('mail.magic_link.heading', ['brand' => $brand]) }}</h1>
    <p style="margin:0 0 20px;color:#3f4450;font-size:15px;line-height:1.6">
        {{ __('mail.magic_link.body') }}
    </p>
    <x-mail.button :href="$url" :theme="$theme">{{ __('mail.magic_link.button', ['brand' => $brand]) }}</x-mail.button>
    <x-mail.link-fallback :href="$url" :theme="$theme" />
</x-mail.layout>

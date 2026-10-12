<x-mail.layout :brand="$brand" :theme="$theme">
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#14161c">{{ __('mail.portal_link.heading', ['organization' => $organization]) }}</h1>
    <p style="margin:0 0 12px;color:#3f4450;font-size:15px;line-height:1.6">
        {{ \App\Mail\MailText::html('mail.portal_link.lead', ['organization' => $organization], ['brand' => $brand]) }}
    </p>
    <ul style="margin:0 0 20px;padding-left:20px;color:#14161c;font-size:15px;line-height:1.7">
        @foreach ($tasks as $task)
            <li>{{ $task }}</li>
        @endforeach
    </ul>
    <x-mail.button :href="$url" :theme="$theme">{{ __('mail.portal_link.button') }}</x-mail.button>
    <p style="margin:20px 0 0;color:#3f4450;font-size:14px;line-height:1.6">
        {{ __('mail.portal_link.expires', ['date' => $expiresOn]) }}
    </p>
    <p style="margin:12px 0 0;color:#5b616e;font-size:13px;line-height:1.6">
        {{ __('mail.portal_link.not_expected', ['brand' => $brand]) }}
    </p>
    <x-mail.link-fallback :href="$url" :theme="$theme" />
</x-mail.layout>

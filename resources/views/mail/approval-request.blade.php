{{--
    An approval waiting for somebody without the app ({@see \App\Mail\ApprovalRequestMail}):
    who is asking, where to answer, when it lapses, and what to do if it was not expected.
    Never what it is for — that is read on the Approvals page, signed in.
--}}
<x-mail.layout :brand="$brand" :theme="$theme" :preheader="__('mail.approval_request.lead', ['app' => $app, 'brand' => $brand])">
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#14161c">{{ __('mail.approval_request.heading', ['app' => $app]) }}</h1>
    <p style="margin:0 0 20px;color:#3f4450;font-size:15px;line-height:1.6">
        {{ __('mail.approval_request.lead', ['app' => $app, 'brand' => $brand]) }}
        {{ __('mail.approval_request.expires', ['time' => $time]) }}
    </p>
    <x-mail.button :href="$url" :theme="$theme">{{ __('mail.approval_request.button') }}</x-mail.button>
    <x-mail.link-fallback :href="$url" :theme="$theme" />
    <p style="margin:20px 0 0;padding:14px 16px;background:#fff8e6;border:1px solid #f1dfae;border-radius:10px;color:#4a3a10;font-size:14px;line-height:1.6">
        <b>{{ __('mail.approval_request.not_you_title') }}</b><br>
        {{ __('mail.approval_request.not_you') }}
    </p>
</x-mail.layout>

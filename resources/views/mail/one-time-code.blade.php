{{--
    A one-time code ({@see \App\Mail\OneTimeCodeMail}): what it is for, the code, how long
    it lasts, and what to do if this was not you — in that order, so the code is never the
    first thing an inbox can preview (the preheader is the sentence instead).

    The code is the largest thing on the card, monospaced so 0/O and 1/l cannot be
    confused, on its own line with nothing else in it, and `user-select: all` so one tap
    or click selects all of it and nothing more. No spaces between the digits: grouping
    reads well and copies badly, and a pasted "482 913" is a failed attempt. It wraps on a
    phone only if it has to (`word-break`), at 30px a 10-digit code fits a 390px screen.
--}}
<x-mail.layout :brand="$brand" :theme="$theme" :preheader="__('mail.one_time_code.'.$kind.'.lead', ['brand' => $brand])">
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#14161c">{{ __('mail.one_time_code.'.$kind.'.heading', ['brand' => $brand]) }}</h1>
    <p style="margin:0 0 20px;color:#3f4450;font-size:15px;line-height:1.6">
        {{ __('mail.one_time_code.'.$kind.'.lead', ['brand' => $brand]) }}
    </p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px"><tr>
        <td align="center" style="background:#f4f5f8;border:1px solid #e4e6ec;border-radius:12px;padding:18px 12px">
            <p style="margin:0 0 6px;color:#5b616e;font-size:13px;line-height:1.4">{{ __('mail.one_time_code.label') }}</p>
            <p style="margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,'Liberation Mono',monospace;font-size:30px;line-height:1.2;font-weight:700;letter-spacing:6px;color:#14161c;word-break:break-all;-webkit-user-select:all;user-select:all">{{ $code }}</p>
        </td>
    </tr></table>
    <p style="margin:0 0 20px;color:#3f4450;font-size:15px;line-height:1.6">
        {{ trans_choice('mail.one_time_code.expires', $minutes, ['count' => $minutes]) }}
        {{ __('mail.one_time_code.never_share', ['brand' => $brand]) }}
    </p>
    <p style="margin:0;padding:14px 16px;background:#fff8e6;border:1px solid #f1dfae;border-radius:10px;color:#4a3a10;font-size:14px;line-height:1.6">
        <b>{{ __('mail.one_time_code.not_you_title') }}</b><br>
        {{ __('mail.one_time_code.'.$kind.'.not_you', ['brand' => $brand]) }}
    </p>
</x-mail.layout>

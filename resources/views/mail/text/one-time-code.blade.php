{!! $brand !!}

{!! __('mail.one_time_code.'.$kind.'.heading', ['brand' => $brand]) !!}

{!! __('mail.one_time_code.'.$kind.'.lead', ['brand' => $brand]) !!}

{!! __('mail.one_time_code.label') !!}

    {!! $code !!}

{!! trans_choice('mail.one_time_code.expires', $minutes, ['count' => $minutes]) !!} {!! __('mail.one_time_code.never_share', ['brand' => $brand]) !!}

{!! __('mail.one_time_code.not_you_title') !!}
{!! __('mail.one_time_code.'.$kind.'.not_you', ['brand' => $brand]) !!}
@include('mail.text.partials.footer')

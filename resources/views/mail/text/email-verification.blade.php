{!! $brand !!}

{!! __('mail.email_verification.heading') !!}

{!! __('mail.email_verification.body', ['brand' => $brand]) !!}

{!! __('mail.email_verification.button') !!}:
{!! $url !!}

{!! __('mail.email_verification.not_you', ['brand' => $brand]) !!}
@include('mail.text.partials.footer')

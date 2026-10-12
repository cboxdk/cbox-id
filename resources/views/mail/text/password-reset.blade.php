{!! $brand !!}

{!! __('mail.password_reset.heading') !!}

{!! __('mail.password_reset.body', ['brand' => $brand]) !!}

{!! __('mail.password_reset.button') !!}:
{!! $url !!}
@include('mail.text.partials.footer')

{!! $brand !!}

{!! __('mail.magic_link.heading', ['brand' => $brand]) !!}

{!! __('mail.magic_link.body') !!}

{!! __('mail.magic_link.button', ['brand' => $brand]) !!}:
{!! $url !!}
@include('mail.text.partials.footer')

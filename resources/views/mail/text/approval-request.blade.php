{!! $brand !!}

{!! __('mail.approval_request.heading', ['app' => $app]) !!}

{!! __('mail.approval_request.lead', ['app' => $app, 'brand' => $brand]) !!} {!! __('mail.approval_request.expires', ['time' => $time]) !!}

{!! __('mail.approval_request.button') !!}:
{!! $url !!}

{!! __('mail.approval_request.not_you_title') !!}
{!! __('mail.approval_request.not_you') !!}
@include('mail.text.partials.footer')

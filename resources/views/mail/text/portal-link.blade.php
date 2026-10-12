{!! $brand !!}

{!! __('mail.portal_link.heading', ['organization' => $organization]) !!}

{!! __('mail.portal_link.lead', ['organization' => $organization, 'brand' => $brand]) !!}

@foreach ($tasks as $task)
- {!! $task !!}
@endforeach

{!! __('mail.portal_link.button') !!}:
{!! $url !!}

{!! __('mail.portal_link.expires', ['date' => $expiresOn]) !!}

{!! __('mail.portal_link.not_expected', ['brand' => $brand]) !!}
@include('mail.text.partials.footer')

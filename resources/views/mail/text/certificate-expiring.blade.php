{!! $brand !!}

{!! $expired ? __('mail.certificate_expiring.heading_expired', ['connection' => $connectionName]) : __('mail.certificate_expiring.heading', ['connection' => $connectionName]) !!}

{!! __($expired ? 'mail.certificate_expiring.lead_expired' : 'mail.certificate_expiring.lead', ['connection' => $connectionName, 'organization' => $organization, 'date' => $expiresOn]) !!}

{!! __('mail.certificate_expiring.what_to_do') !!}

{!! __('mail.certificate_expiring.why', ['brand' => $brand]) !!}
@include('mail.text.partials.footer')

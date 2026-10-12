{!! $brand !!}

{!! __('mail.admin_assigned_password.heading') !!}

{!! __('mail.admin_assigned_password.body') !!}@if ($temporary) {!! __('mail.admin_assigned_password.temporary') !!}@endif


    {!! $password !!}

@if ($expiresOn)
{!! __('mail.admin_assigned_password.expires', ['date' => $expiresOn]) !!}

@endif
{!! __('mail.admin_assigned_password.not_expected') !!}
@include('mail.text.partials.footer')

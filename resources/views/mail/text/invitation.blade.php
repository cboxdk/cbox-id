{!! $brand !!}

{!! __('mail.invitation.heading', ['organization' => $organization]) !!}

@if ($roleName){!! __('mail.invitation.invited_as', ['inviter' => $inviter, 'organization' => $organization, 'role' => $roleName]) !!}@else{!! __('mail.invitation.invited', ['inviter' => $inviter, 'organization' => $organization]) !!}@endif
 @if ($app){!! __('mail.invitation.accept_app', ['app' => $app, 'organization' => $organization]) !!}@else{!! __('mail.invitation.accept') !!}@endif


{!! __('mail.invitation.button') !!}:
{!! $url !!}

{!! __('mail.invitation.note') !!}
@include('mail.text.partials.footer')

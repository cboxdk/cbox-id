{!! $brand !!}

{!! __('mail.organization_invite.heading', ['organization' => $organization, 'brand' => $brand]) !!}

@if ($roleName){!! __('mail.organization_invite.invited_as', ['inviter' => $inviter, 'organization' => $organization, 'role' => $roleName, 'brand' => $brand]) !!}@else{!! __('mail.organization_invite.invited', ['inviter' => $inviter, 'organization' => $organization, 'brand' => $brand]) !!}@endif


{!! __('mail.organization_invite.button') !!}:
{!! $url !!}

{!! __('mail.organization_invite.note') !!}
@include('mail.text.partials.footer')

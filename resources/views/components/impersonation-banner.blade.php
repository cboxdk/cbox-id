@php
    // Support-impersonation banner — unmissable, and the ONLY exit control there is.
    //
    // Extracted from `layouts.app` because it was only in `layouts.app`. The Impersonate
    // button moved to `/platform/organizations`, which renders on `layouts.platform` on
    // the account-root host — so an operator who started an impersonation from where the
    // button now lives got a banner-less page and no way back out except POSTing
    // `/impersonation/exit` by hand. A control that exists on one of the two layouts a
    // person can be holding is not a control.
    $impersonation = app(\App\Platform\Impersonation::class)->active();
    $impersonationEmail = $impersonation === null
        ? null
        : app(\Cbox\Id\Identity\Contracts\Subjects::class)->find($impersonation->subject)?->email;
@endphp
@if ($impersonation !== null)
    <div role="alert"
         style="position:sticky;top:0;z-index:80;width:100%;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:0.75rem;padding:0.6rem 1rem;background:var(--destructive);color:var(--destructive-foreground);font-size:0.85rem;font-weight:600">
        <span><span aria-hidden="true">⚠</span>
            You are impersonating {{ $impersonationEmail ?? $impersonation->subject }} for support. Everything you do is logged.
            @if ($impersonation->reason !== null)<span style="font-weight:400;opacity:0.9">(reason: {{ $impersonation->reason }})</span>@endif
        </span>
        <form method="POST" action="{{ route('impersonation.exit') }}">@csrf
            <button type="submit" style="border:1px solid rgba(255,255,255,0.7);border-radius:6px;padding:3px 12px;background:transparent;color:inherit;font-weight:600;cursor:pointer">Exit impersonation</button>
        </form>
    </div>
@endif

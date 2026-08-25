{{--
    THE ONE ROOT DOCUMENT. Every page the console and the sign-in surfaces serve is
    mounted into this file; the chrome around a page — rail, sub-nav, auth hero, portal
    shell — is a React layout, not a blade one, so there is nothing here that varies by
    page beyond the props Inertia hands over.

    What stays on the server, and why it has to:

      - THE THEME, as an attribute on <html>. It is read from a cookie because the server
        cannot read localStorage, and it must be on the first byte because the bundle is
        deferred by definition. {@see \App\Platform\Theme}.
      - THE BRAND'S TOKEN OVERRIDE, as a <style> block in <head>. A branded sign-in that
        painted Cbox blue and then turned the customer's colour would be worse than one
        that was never branded. {@see \App\Platform\Appearance\BrandContext}.

    Both were true under Volt and both are still true; what changed is that they are now
    resolved once, here, instead of by whichever layout a page happened to declare.
--}}
@php
    $brand = app(\App\Platform\Appearance\BrandContext::class);
    $appName = $brand->name() ?? config('cbox-id.branding.name', 'Cbox ID');
    $appearanceCss = $brand->css();
@endphp
<!DOCTYPE html>
{{--
    `cbx-nav-pinned` on <html> from the cookie, for the same reason the theme is: the
    rail is 52px collapsed and 210px pinned, and a class applied by JavaScript arrives
    after the first paint — so the console animated 52 → 210px on every hard refresh for
    anybody who had pinned it.
--}}
<html lang="en"{!! \App\Platform\Theme::attribute() !!} class="h-full {{ request()->cookie('cbox-nav-pinned') === '1' ? 'cbx-nav-pinned' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Root paths, not /brand/*. Icon harvesters — password managers, link unfurlers,
         browsers restoring a tab before the HTML parses — probe /favicon.ico and
         /apple-touch-icon.png directly and never read these tags. --}}
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b0b0b" media="(prefers-color-scheme: dark)">

    {{-- The server's best answer, refined by the page's own <Head> once React mounts.
         `inertia` marks it so Inertia replaces rather than duplicates it. --}}
    <title inertia>{{ ($title ?? null) ? $title.' · '.$appName : $appName }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.tsx'])

    {{-- The organization's custom sign-in theme (Theme Editor): a coherent token
         override for light and dark. Absent → the platform default stands. --}}
    @if ($appearanceCss){!! $appearanceCss !!}@endif

    {{-- The full per-tenant palette when the whitelabel module is installed; inert
         otherwise. Registered by the console kit. --}}
    @consoleBrandingStyle

    @inertiaHead
</head>
{{--
    The background is painted HERE, not by a layout. Each React layout paints its own
    surface, but the document behind them must already be the right colour: a body left
    transparent shows the browser's default white for the frame between the first paint
    and the mount, which on a dark theme is a full-screen flash.
--}}
<body class="h-full" style="background:var(--background);color:var(--foreground)">
    {{-- Before the app root, so it is the first thing a keyboard reaches on every page
         — including the sign-in surfaces, which under the old layouts had no landmark
         and no skip target at all. --}}
    <a href="#main-content" class="skip-link">Skip to content</a>

    @inertia
</body>
</html>

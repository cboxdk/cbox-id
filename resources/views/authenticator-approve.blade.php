<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('hosted.authenticator_link.title') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        body { background: var(--bg); color: var(--text); font-family: var(--font-sans); line-height: 1.6; margin: 0; }
        main { max-width: 26rem; margin: 0 auto; padding: 3.5rem 1rem 4rem; }
        .mark { width: 3rem; height: 3rem; border-radius: var(--radius-md, .75rem); background: var(--accent-soft); color: var(--accent-strong); display: grid; place-items: center; }
        h1 { font-family: var(--font-display, var(--font-sans)); font-size: 1.625rem; line-height: 1.2; font-weight: 650; margin: 1.25rem 0 .5rem; }
        p { margin: 0 0 1rem; color: var(--muted-foreground); }
        .lead { color: var(--text); }
        .actions { display: grid; gap: .75rem; margin: 1.75rem 0 1.25rem; }
        .button { display: block; text-align: center; padding: .8rem 1rem; border-radius: var(--radius, .625rem); font-weight: 600; text-decoration: none; border: 1px solid var(--border); color: var(--text); background: var(--surface); }
        .button.primary { background: var(--accent); color: var(--accent-foreground, #fff); border-color: transparent; }
        .button:focus-visible { outline: 2px solid var(--accent-strong); outline-offset: 2px; }
    </style>
</head>
<body>
<main>
    <div class="mark" aria-hidden="true">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="3"/><path d="M11 18h2"/></svg>
    </div>
    <h1>{{ __('hosted.authenticator_link.title') }}</h1>
    <p class="lead">{{ __('hosted.authenticator_link.lead') }}</p>
    <p>{{ __('hosted.authenticator_link.other_phone') }}</p>

    @if ($phone)
        <div class="actions">
            <a class="button primary" href="{{ $appUrl }}" data-open-app>{{ __('hosted.authenticator_link.open') }}</a>
            @if ($storeUrl !== null)
                <a class="button" href="{{ $storeUrl }}" rel="noopener">{{ __('hosted.authenticator_link.install') }}</a>
            @endif
        </div>
    @endif

    <p>{{ __('hosted.authenticator_link.back') }}</p>
</main>
</body>
</html>

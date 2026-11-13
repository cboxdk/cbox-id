@php
    // Surface the active trace id so an operator can jump straight from a broken
    // page into the telemetry backend. The TraceRequest middleware keeps the span
    // open through error rendering, so this is the id of the request that failed.
    $traceId = null;
    // The deployment's own name, not ours — the same one its sign-in pages and mail carry.
    $brand = config('cbox-id.branding.name', 'Cbox ID');
    if (class_exists(\Cbox\Telemetry\Facades\Telemetry::class)) {
        try {
            $traceId = \Cbox\Telemetry\Facades\Telemetry::traceId();
        } catch (\Throwable) {
            $traceId = null;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('errors.layout.default_title')) · {{ $brand }}</title>
    @vite(['resources/css/app.css', 'resources/js/errors.ts'])
</head>
<body class="h-full" style="background:var(--canvas);color:var(--foreground)">
    {{--
        THE SAME FRAME AS THE SIGN-IN PAGES (resources/css/auth.css): the canvas wash, the
        top bar with the product's mark, the message in a card. Plain Blade and static
        markup on purpose — an error page has to render when the application cannot.
    --}}
    <div class="auth-shell auth-page">
        <header class="auth-topbar">
            <a href="{{ url('/') }}" class="auth-brand" aria-label="{{ $brand }}">
                @if ($brand === 'Cbox ID')
                    <span class="auth-wordmark" aria-hidden="true"></span>
                    <svg viewBox="0 0 64 64" class="auth-monogram" aria-hidden="true" focusable="false">
                        <rect x="0" y="0" width="64" height="64" rx="15" fill="var(--primary)"></rect>
                        <text x="32" y="43.5" text-anchor="middle" fill="var(--primary-foreground)" font-family="var(--font-display)" font-weight="700" font-size="29" letter-spacing="-0.04em">ID</text>
                    </svg>
                    <span class="auth-brand-name" aria-hidden="true">ID</span>
                @else
                    <span class="auth-brand-initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($brand, 0, 1)) }}</span>
                    <span class="auth-brand-name">{{ $brand }}</span>
                @endif
            </a>
        </header>

        <main role="main" id="main-content" class="auth-main">
            <div class="auth-column">
                <div class="auth-card">
                    <p class="mono" style="font-size:12px;color:var(--muted-foreground);margin:0 0 8px">
                        @yield('code', 'ERROR')
                    </p>
                    <h1 class="auth-title" style="margin:0">
                        @yield('title', __('errors.layout.default_heading'))
                    </h1>
                    <p style="font-size:14px;line-height:1.55;color:var(--muted-foreground);margin:6px 0 24px">
                        @yield('message', __('errors.layout.default_message'))
                    </p>

                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        @yield('actions')
                            <a href="{{ url('/') }}" class="btn btn-primary btn-lg">
                                {{ __('errors.layout.home') }}
                            </a>
                            <button type="button" data-error-reload class="btn btn-ghost btn-lg">
                                {{ __('errors.layout.reload') }}
                            </button>
                    </div>

                    @if ($traceId)
                        <div class="auth-card-foot" style="text-align:left">
                            <p style="font-size:12px;margin:0 0 8px">
                                {{ __('errors.layout.share_trace') }}
                            </p>
                            <button type="button" data-copy-trace="{{ $traceId }}"
                                    title="{{ __('errors.layout.copy_trace') }}"
                                    class="mono"
                                    style="display:inline-flex;align-items:center;gap:8px;max-width:100%;background:var(--card);border:1px solid var(--border);border-radius:var(--radius-md);padding:6px 10px;cursor:pointer;font-size:12px;color:var(--foreground);overflow-wrap:anywhere;text-align:left">
                                <span style="color:var(--muted-foreground)">{{ __('errors.layout.trace_id') }}</span>
                                <span>{{ $traceId }}</span>
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--muted-foreground);flex-shrink:0" aria-hidden="true">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                </svg>
                            </button>
                            <span data-copy-feedback aria-live="polite" style="display:block;font-size:12px;color:var(--accent-strong);margin-top:4px;min-height:1em;visibility:hidden">{{ __('errors.layout.copied') }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </main>
    </div>
</body>
</html>

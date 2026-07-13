@php
    // Surface the active trace id so an operator can jump straight from a broken
    // page into the telemetry backend. The TraceRequest middleware keeps the span
    // open through error rendering, so this is the id of the request that failed.
    $traceId = null;
    if (class_exists(\Cbox\Telemetry\Facades\Telemetry::class)) {
        try {
            $traceId = \Cbox\Telemetry\Facades\Telemetry::traceId();
        } catch (\Throwable) {
            $traceId = null;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Error') · Cbox ID</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full" style="background:var(--bg);color:var(--text)">
    <main role="main"
          style="min-height:100vh;display:grid;place-items:center;padding:1.5rem;box-sizing:border-box">
        <div style="max-width:30rem;width:100%;text-align:center">
            <div style="display:inline-flex;align-items:center;gap:.5rem;font-weight:600;font-size:.95rem;color:var(--text);margin-bottom:2rem">
                <span aria-hidden="true"
                      style="width:1.4rem;height:1.4rem;border-radius:.4rem;display:inline-grid;place-items:center;background:var(--accent);color:#fff;font-size:.8rem">C</span>
                Cbox ID
            </div>

            <p style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.8rem;letter-spacing:.08em;color:var(--muted);margin:0 0 .5rem">
                @yield('code', 'ERROR')
            </p>
            <h1 style="font-size:1.5rem;font-weight:650;color:var(--text);margin:0 0 .6rem;text-wrap:balance">
                @yield('title', 'Something went wrong')
            </h1>
            <p style="font-size:.925rem;color:var(--muted);line-height:1.55;margin:0 auto 1.75rem;max-width:24rem">
                @yield('message', 'An unexpected error occurred. Please try again.')
            </p>

            <div style="display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap">
                @yield('actions')
                    <a href="{{ url('/') }}"
                       style="background:var(--accent);color:#fff;text-decoration:none;border-radius:.6rem;padding:.6rem 1.2rem;font-size:.9rem;font-weight:500">
                        Back to dashboard
                    </a>
                    <button type="button" data-error-reload
                            style="background:transparent;color:var(--muted);border:1px solid var(--border);border-radius:.6rem;padding:.6rem 1.2rem;font-size:.9rem;cursor:pointer">
                        Reload
                    </button>
            </div>

            @if ($traceId)
                <div style="margin-top:2.25rem;padding-top:1.5rem;border-top:1px solid var(--border)">
                    <p style="font-size:.75rem;color:var(--muted);margin:0 0 .5rem">
                        Share this with support to help us trace what happened.
                    </p>
                    <button type="button" data-copy-trace="{{ $traceId }}"
                            title="Copy trace ID"
                            style="display:inline-flex;align-items:center;gap:.5rem;background:var(--surface);border:1px solid var(--border);border-radius:.5rem;padding:.4rem .7rem;cursor:pointer;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;color:var(--text)">
                        <span style="color:var(--muted)">Trace ID</span>
                        <span>{{ $traceId }}</span>
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--muted)">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                    </button>
                    <span data-copy-feedback aria-live="polite" style="display:block;font-size:.72rem;color:var(--accent);margin-top:.4rem;min-height:1em;visibility:hidden">Copied</span>
                </div>
            @endif
        </div>
    </main>
</body>
</html>

@php
    // The environment resolved for this request (by host). A sandbox realm is for
    // development/testing — make that unmistakable so nobody mistakes it for prod.
    // Shares the request's one environment read with the delete dialog and its badge.
    $sandbox = app(App\Platform\CurrentEnvironment::class)->isSandbox();
@endphp

@if ($sandbox)
    <div role="status" aria-live="polite"
         class="w-full flex items-center justify-center gap-2 px-4 py-2 text-xs font-semibold text-center"
         style="background:color-mix(in oklch, var(--warning) 16%, var(--background)); color:var(--warning-strong); border-bottom:1px solid color-mix(in oklch, var(--warning) 30%, transparent)">
        <x-icon name="shield" class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
        <span>Sandbox environment — for testing only. This is <strong>not</strong> your production sign-in, and no real emails are sent.</span>
    </div>
@endif

@php $providers = \App\Platform\SocialProviders::configured(); @endphp

@if (! empty($providers))
    @php
        $marks = [
            'google' => '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.76h3.56c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.56-2.76c-.98.66-2.23 1.06-3.72 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0012 23z"/><path fill="#FBBC05" d="M5.84 14.11a6.6 6.6 0 010-4.22V7.05H2.18a11 11 0 000 9.9l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1a11 11 0 00-9.82 6.05l3.66 2.84C6.71 7.29 9.14 5.38 12 5.38z"/></svg>',
            'github' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 1.5a10.5 10.5 0 00-3.32 20.47c.52.1.71-.23.71-.5v-1.76c-2.92.64-3.54-1.41-3.54-1.41-.48-1.22-1.17-1.54-1.17-1.54-.95-.65.07-.64.07-.64 1.05.07 1.6 1.08 1.6 1.08.94 1.6 2.46 1.14 3.06.87.1-.68.37-1.14.67-1.4-2.33-.27-4.78-1.17-4.78-5.19 0-1.15.41-2.09 1.08-2.83-.11-.27-.47-1.34.1-2.8 0 0 .88-.28 2.88 1.08a10 10 0 015.24 0c2-1.36 2.88-1.08 2.88-1.08.57 1.46.21 2.53.1 2.8.67.74 1.08 1.68 1.08 2.83 0 4.03-2.46 4.92-4.8 5.18.38.33.72.97.72 1.96v2.9c0 .28.19.61.72.5A10.5 10.5 0 0012 1.5z"/></svg>',
            'microsoft' => '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="#F25022" d="M1 1h10v10H1z"/><path fill="#7FBA00" d="M13 1h10v10H13z"/><path fill="#00A4EF" d="M1 13h10v10H1z"/><path fill="#FFB900" d="M13 13h10v10H13z"/></svg>',
        ];
    @endphp

    <div class="space-y-2.5">
        @foreach ($providers as $key => $label)
            <a href="{{ route('social.redirect', $key) }}" class="btn btn-ghost w-full">
                {!! $marks[$key] ?? '' !!}
                <span>Continue with {{ $label }}</span>
            </a>
        @endforeach
    </div>
@endif

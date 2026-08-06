@props(['provider' => '', 'size' => 18])

{{--
    The provider's mark, for sign-in buttons and the setup catalogue.

    One component rather than a copy per surface: a button on the sign-in page and the
    card in the console must show the same thing, or an administrator picks "GitHub" from
    one list and cannot find it in the other.

    Marks are simplified — recognisable at 18px, which is the only size that matters
    here, and small enough to inline rather than fetch. Providers whose logo does not
    survive being reduced to a couple of paths get a monogram in their brand colour,
    which reads as deliberate rather than as a missing image. A `?` would not.
--}}

@php
    $key = (string) $provider;
    $s = (int) $size;

    // Brand colours for the monogram fallback. Taken from each provider's own published
    // brand guidance, so the fallback still looks like the product it names.
    $monograms = [
        'okta' => ['O', '#007DC1'],
        'auth0' => ['A', '#EB5424'],
        'keycloak' => ['K', '#008AAA'],
        'gitlab' => ['G', '#FC6D26'],
    ];
@endphp

@switch($key)
    @case('google')
        <svg width="{{ $s }}" height="{{ $s }}" viewBox="0 0 24 24" aria-hidden="true" {{ $attributes }}><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.76h3.56c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.56-2.76c-.98.66-2.23 1.06-3.72 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0012 23z"/><path fill="#FBBC05" d="M5.84 14.11a6.6 6.6 0 010-4.22V7.05H2.18a11 11 0 000 9.9l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1a11 11 0 00-9.82 6.05l3.66 2.84C6.71 7.29 9.14 5.38 12 5.38z"/></svg>
        @break

    @case('github')
        <svg width="{{ $s }}" height="{{ $s }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" {{ $attributes }}><path d="M12 1.5a10.5 10.5 0 00-3.32 20.47c.52.1.71-.23.71-.5v-1.76c-2.92.64-3.54-1.41-3.54-1.41-.48-1.22-1.17-1.54-1.17-1.54-.95-.65.07-.64.07-.64 1.05.07 1.6 1.08 1.6 1.08.94 1.6 2.46 1.14 3.06.87.1-.68.37-1.14.67-1.4-2.33-.27-4.78-1.17-4.78-5.19 0-1.15.41-2.09 1.08-2.83-.11-.27-.47-1.34.1-2.8 0 0 .88-.28 2.88 1.08a10 10 0 015.24 0c2-1.36 2.88-1.08 2.88-1.08.57 1.46.21 2.53.1 2.8.67.74 1.08 1.68 1.08 2.83 0 4.03-2.46 4.92-4.8 5.18.38.33.72.97.72 1.96v2.9c0 .28.19.61.72.5A10.5 10.5 0 0012 1.5z"/></svg>
        @break

    @case('microsoft')
        <svg width="{{ $s - 2 }}" height="{{ $s - 2 }}" viewBox="0 0 24 24" aria-hidden="true" {{ $attributes }}><path fill="#F25022" d="M1 1h10v10H1z"/><path fill="#7FBA00" d="M13 1h10v10H13z"/><path fill="#00A4EF" d="M1 13h10v10H1z"/><path fill="#FFB900" d="M13 13h10v10H13z"/></svg>
        @break

    @case('apple')
        {{-- currentColor: Apple's guidance is a black mark on light and white on dark. --}}
        <svg width="{{ $s }}" height="{{ $s }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" {{ $attributes }}><path d="M17.05 12.72c-.03-2.62 2.14-3.88 2.24-3.94-1.22-1.79-3.12-2.03-3.79-2.06-1.61-.16-3.15.95-3.97.95-.82 0-2.08-.93-3.42-.9-1.76.03-3.38 1.02-4.29 2.6-1.83 3.17-.47 7.86 1.31 10.43.87 1.26 1.91 2.67 3.28 2.62 1.32-.05 1.81-.85 3.4-.85 1.59 0 2.04.85 3.43.82 1.42-.02 2.31-1.28 3.17-2.55.99-1.46 1.4-2.87 1.43-2.95-.03-.01-2.75-1.05-2.78-4.17zM14.6 4.9c.73-.88 1.22-2.11 1.08-3.33-1.05.04-2.32.7-3.07 1.58-.67.78-1.26 2.02-1.1 3.22 1.17.09 2.36-.6 3.09-1.47z"/></svg>
        @break

    @case('facebook')
        <svg width="{{ $s }}" height="{{ $s }}" viewBox="0 0 24 24" aria-hidden="true" {{ $attributes }}><path fill="#1877F2" d="M24 12a12 12 0 10-13.87 11.85v-8.38H7.08V12h3.05V9.36c0-3.01 1.79-4.67 4.53-4.67 1.31 0 2.69.23 2.69.23v2.96h-1.52c-1.49 0-1.96.93-1.96 1.88V12h3.33l-.53 3.47h-2.8v8.38A12 12 0 0024 12z"/></svg>
        @break

    @case('discord')
        <svg width="{{ $s }}" height="{{ $s }}" viewBox="0 0 24 24" aria-hidden="true" {{ $attributes }}><path fill="#5865F2" d="M20.32 4.94A19.8 19.8 0 0015.43 3.4l-.25.45c-.86.16-1.7.4-2.5.72a17 17 0 00-1.36 0c-.8-.32-1.64-.56-2.5-.72L8.57 3.4a19.8 19.8 0 00-4.89 1.54C1.05 8.85.34 12.66.7 16.42a19.9 19.9 0 006.02 3.05l.48-.68c.06-.09.03-.2-.06-.24a13 13 0 01-1.86-.89c-.1-.06-.11-.2-.02-.27l.37-.29c.06-.05.14-.06.2-.03a14.14 14.14 0 0012.03 0c.07-.03.15-.02.21.03l.37.29c.09.07.08.21-.02.27-.6.35-1.22.65-1.87.89-.09.04-.12.15-.06.24l.48.68a19.9 19.9 0 006.02-3.05c.42-4.35-.71-8.13-2.98-11.48zM8.68 14.35c-1.17 0-2.13-1.08-2.13-2.4 0-1.31.94-2.39 2.13-2.39 1.2 0 2.16 1.09 2.14 2.4 0 1.31-.95 2.39-2.14 2.39zm6.66 0c-1.17 0-2.13-1.08-2.13-2.4 0-1.31.94-2.39 2.13-2.39 1.2 0 2.15 1.09 2.13 2.4 0 1.31-.94 2.39-2.13 2.39z"/></svg>
        @break

    @case('slack')
        <svg width="{{ $s }}" height="{{ $s }}" viewBox="0 0 24 24" aria-hidden="true" {{ $attributes }}><path fill="#E01E5A" d="M5.04 15.17a2.53 2.53 0 11-2.52-2.53h2.52v2.53zm1.27 0a2.53 2.53 0 015.05 0v6.31a2.53 2.53 0 01-5.05 0v-6.31z"/><path fill="#36C5F0" d="M8.83 5.05a2.53 2.53 0 112.53-2.53v2.53H8.83zm0 1.28a2.53 2.53 0 010 5.05H2.52a2.53 2.53 0 010-5.05h6.31z"/><path fill="#2EB67D" d="M18.95 8.83a2.53 2.53 0 112.53 2.53h-2.53V8.83zm-1.27 0a2.53 2.53 0 01-5.05 0V2.52a2.53 2.53 0 015.05 0v6.31z"/><path fill="#ECB22E" d="M15.17 18.95a2.53 2.53 0 11-2.53 2.53v-2.53h2.53zm0-1.27a2.53 2.53 0 010-5.05h6.31a2.53 2.53 0 010 5.05h-6.31z"/></svg>
        @break

    @default
        @php([$letter, $colour] = $monograms[$key] ?? [mb_strtoupper(mb_substr($key === '' ? '?' : $key, 0, 1)), 'var(--accent)'])
        <span
            aria-hidden="true"
            class="inline-flex items-center justify-center rounded-[4px] font-semibold shrink-0"
            style="width:{{ $s }}px;height:{{ $s }}px;background:{{ $colour }};color:#fff;font-size:{{ max(9, (int) round($s * 0.58)) }}px;line-height:1"
            {{ $attributes }}
        >{{ $letter }}</span>
@endswitch

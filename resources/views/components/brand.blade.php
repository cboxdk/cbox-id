@props(['compact' => false])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5 select-none']) }}>
    {{-- Cbox · ID app monogram (design-system assets/monograms/id.svg), theme-aware
         via the primary token so the mark matches light and dark. --}}
    <svg viewBox="0 0 64 64" width="32" height="32" role="img"
         aria-label="{{ config('cbox-id.branding.name', 'Cbox ID') }}"
         style="flex-shrink:0;border-radius:9px;box-shadow:var(--shadow-card)">
        <rect x="2" y="2" width="60" height="60" rx="14" fill="var(--primary)" />
        <text x="32" y="44" text-anchor="middle" fill="var(--primary-foreground)"
              font-family="var(--font-display)" font-weight="700" font-size="30" letter-spacing="-0.04em">ID</text>
    </svg>
    @unless($compact)
        <span class="font-semibold tracking-tight" style="font-size:1.02rem;color:var(--foreground)">{{ config('cbox-id.branding.name', 'Cbox ID') }}</span>
    @endunless
</span>

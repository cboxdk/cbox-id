@props(['compact' => false])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5 select-none']) }}>
    <span class="grid place-items-center rounded-lg" style="width:2rem;height:2rem;background:var(--accent);color:var(--accent-fg);box-shadow:var(--shadow)">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 2.75l7.5 3.5v5.25c0 4.28-2.98 7.86-7.5 9.25-4.52-1.39-7.5-4.97-7.5-9.25V6.25l7.5-3.5z" />
            <path d="M9 12.25l2.25 2.25L15.5 10" />
        </svg>
    </span>
    @unless($compact)
        <span class="font-semibold tracking-tight" style="font-size:1.02rem;color:var(--text)">Cbox&nbsp;ID</span>
    @endunless
</span>

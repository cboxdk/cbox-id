@php($count = (int) ($count ?? 0))
{{-- Renders inside the Cbox console shell, which themes via CSS variables + a
     [data-theme] attribute — NOT a Tailwind `dark:` class. So this card is styled
     with the host design tokens (var(--card)/--foreground/--muted/--accent), never
     `bg-white dark:…` utilities, which would render permanently light + off-brand. --}}
<div class="card p-5">
    <div class="flex items-center gap-2 text-sm" style="color:var(--muted)">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4" aria-hidden="true"
             style="color:{{ $count > 0 ? 'var(--warning)' : 'var(--success)' }}">
            <path fill-rule="evenodd" d="M10 1.5 3 4.2v4.4c0 4 2.8 7.7 7 9.9 4.2-2.2 7-5.9 7-9.9V4.2L10 1.5Zm-.75 6a.75.75 0 0 1 1.5 0v3a.75.75 0 0 1-1.5 0v-3ZM10 13a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd" />
        </svg>
        Risk events (24h)
    </div>
    <p class="mt-2 text-3xl font-semibold tracking-tight mono" style="color:var(--foreground)">{{ $count }}</p>
    <p class="text-xs" style="color:var(--faint)">{{ $count === 1 ? 'signal elevated' : 'elevated' }} in the last 24 hours</p>
    <a href="{{ route('risk-plus.events') }}" class="mt-3 inline-block text-sm font-medium" style="color:var(--accent)">
        Review risk events &rarr;
    </a>
</div>

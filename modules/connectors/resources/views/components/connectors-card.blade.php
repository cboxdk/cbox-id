@php($count = (int) ($count ?? 0))
<div class="card p-5">
    <div class="flex items-center gap-3">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg" style="background:var(--accent-soft);color:var(--primary)">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                <path d="M7 4a1 1 0 0 1 1 1v2h4V5a1 1 0 1 1 2 0v2h.5A1.5 1.5 0 0 1 16 8.5V10a4 4 0 0 1-4 4h-1v2a1 1 0 1 1-2 0v-2H8a4 4 0 0 1-4-4V8.5A1.5 1.5 0 0 1 5.5 7H6V5a1 1 0 0 1 1-1Z" />
            </svg>
        </span>
        <div>
            <p class="text-sm font-medium" style="color:var(--muted)">Active connectors</p>
            <p class="text-lg font-semibold tabular-nums" style="color:var(--foreground)">
                {{ number_format($count) }}
            </p>
        </div>
    </div>
    <a href="{{ route('connectors.connections') }}" class="mt-4 inline-block text-sm font-medium" style="color:var(--accent)">
        View connections &rarr;
    </a>
</div>

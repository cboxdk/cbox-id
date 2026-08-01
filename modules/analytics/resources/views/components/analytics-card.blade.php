@php($logins = (int) ($logins ?? 0))
<div class="card p-5">
    <div class="flex items-center gap-3">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg" style="background:var(--accent-soft);color:var(--primary)">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                <path fill-rule="evenodd" d="M15.5 2A1.5 1.5 0 0 1 17 3.5v13a1.5 1.5 0 0 1-1.5 1.5h-1a1.5 1.5 0 0 1-1.5-1.5v-13A1.5 1.5 0 0 1 14.5 2h1Zm-5 5A1.5 1.5 0 0 1 12 8.5v8a1.5 1.5 0 0 1-1.5 1.5h-1A1.5 1.5 0 0 1 8 16.5v-8A1.5 1.5 0 0 1 9.5 7h1Zm-5 4A1.5 1.5 0 0 1 7 12.5v4A1.5 1.5 0 0 1 5.5 18h-1A1.5 1.5 0 0 1 3 16.5v-4A1.5 1.5 0 0 1 4.5 11h1Z" clip-rule="evenodd" />
            </svg>
        </span>
        <div>
            <p class="text-sm font-medium" style="color:var(--muted)">Logins (24h)</p>
            <p class="text-lg font-semibold tabular-nums mono" style="color:var(--foreground)">
                {{ number_format($logins) }}
            </p>
        </div>
    </div>
    <a href="{{ route('analytics.overview') }}" class="mt-4 inline-block text-sm font-medium" style="color:var(--accent-strong)">
        View analytics &rarr;
    </a>
</div>

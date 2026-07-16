@php($pending = (int) ($pending ?? 0))
<div class="card p-5">
    <div class="flex items-center gap-3">
        <span class="cbx-stat-icon {{ $pending > 0 ? 'cbx-stat-icon--warning' : 'cbx-stat-icon--success' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 1.5 3 4.2v4.4c0 4 2.8 7.7 7 9.9 4.2-2.2 7-5.9 7-9.9V4.2L10 1.5Zm3.03 6.28a.75.75 0 0 0-1.06-1.06L9 9.69 8.03 8.72a.75.75 0 0 0-1.06 1.06l1.5 1.5a.75.75 0 0 0 1.06 0l3.5-3.5Z" clip-rule="evenodd" />
            </svg>
        </span>
        <div>
            <p class="text-sm font-medium" style="color:var(--muted)">Audit export</p>
            <p class="text-lg font-semibold mono" style="color:var(--foreground)">
                {{ number_format($pending) }} pending
            </p>
        </div>
    </div>
    <p class="mt-3 text-xs" style="color:var(--muted)">
        @if ($lastRun ?? null)
            Last run {{ $lastRun->finished_at?->diffForHumans() ?? '—' }} · {{ $lastRun->status }}
        @else
            No export runs yet.
        @endif
    </p>
    <a href="{{ route('compliance.exports') }}" class="mt-4 inline-block text-sm font-medium" style="color:var(--accent)">
        View exports &amp; retention &rarr;
    </a>
</div>

@php($pending = (int) ($pending ?? 0))
<div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
    <div class="flex items-center gap-3">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg {{ $pending > 0 ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 1.5 3 4.2v4.4c0 4 2.8 7.7 7 9.9 4.2-2.2 7-5.9 7-9.9V4.2L10 1.5Zm3.03 6.28a.75.75 0 0 0-1.06-1.06L9 9.69 8.03 8.72a.75.75 0 0 0-1.06 1.06l1.5 1.5a.75.75 0 0 0 1.06 0l3.5-3.5Z" clip-rule="evenodd" />
            </svg>
        </span>
        <div>
            <p class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Audit export</p>
            <p class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                {{ number_format($pending) }} pending
            </p>
        </div>
    </div>
    <p class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">
        @if ($lastRun ?? null)
            Last run {{ $lastRun->finished_at?->diffForHumans() ?? '—' }} · {{ $lastRun->status }}
        @else
            No export runs yet.
        @endif
    </p>
    <a href="{{ route('compliance.exports') }}" class="mt-4 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
        View exports &amp; retention &rarr;
    </a>
</div>

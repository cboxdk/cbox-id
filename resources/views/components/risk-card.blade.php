@php($count = (int) ($count ?? 0))
<div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
    <div class="flex items-center gap-3">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg {{ $count > 0 ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 1.5 3 4.2v4.4c0 4 2.8 7.7 7 9.9 4.2-2.2 7-5.9 7-9.9V4.2L10 1.5Zm-.75 6a.75.75 0 0 1 1.5 0v3a.75.75 0 0 1-1.5 0v-3ZM10 13a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd" />
            </svg>
        </span>
        <div>
            <p class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Risk events (24h)</p>
            <p class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                {{ $count }} elevated
            </p>
        </div>
    </div>
    <a href="{{ route('risk-plus.events') }}" class="mt-4 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
        Review risk events &rarr;
    </a>
</div>

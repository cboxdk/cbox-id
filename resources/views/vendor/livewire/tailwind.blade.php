{{--
    THE PAGER, IN THIS PRODUCT'S OWN TOKENS.

    Livewire's shipped `tailwind` pagination view is a dozen dark-mode utilities over a
    hardcoded white/grey palette. This application does not theme with
    `prefers-color-scheme` — the console's toggle writes `:root[data-theme]`, and the two
    are independent. So a person on a light OS who chose Dark got a white pill on a dark
    page, and a person on a dark OS who chose Light got a grey-800 block on a light one.
    Every paginated console page had it.

    It was invisible to the guard written for exactly this — AccessibilityTest's
    palette sweep walks `resources/views`, and the offending file lived under `vendor/`,
    which is not published until somebody publishes it. This file is that publish.
--}}
<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}"
             class="flex items-center justify-between gap-3 flex-wrap">
            {{-- The count first on mobile, where the buttons wrap under it. --}}
            <p class="text-xs" style="color:var(--faint)">
                {!! __('Showing') !!}
                <span class="font-medium" style="color:var(--muted)">{{ $paginator->firstItem() ?? 0 }}</span>
                {!! __('to') !!}
                <span class="font-medium" style="color:var(--muted)">{{ $paginator->lastItem() ?? 0 }}</span>
                @if (! $paginator instanceof \Illuminate\Pagination\Paginator)
                    {!! __('of') !!}
                    <span class="font-medium" style="color:var(--muted)">{{ $paginator->total() }}</span>
                @endif
            </p>

            <div class="flex items-center gap-2">
                @if ($paginator->onFirstPage())
                    <span class="btn btn-ghost btn-sm" aria-disabled="true" style="opacity:.5;pointer-events:none">
                        {!! __('pagination.previous') !!}
                    </span>
                @else
                    <button type="button" dusk="previousPage" wire:click="previousPage('{{ $paginator->getPageName() }}')"
                            wire:loading.attr="disabled" rel="prev" class="btn btn-ghost btn-sm">
                        {!! __('pagination.previous') !!}
                    </button>
                @endif

                <span class="text-xs" style="color:var(--faint)">
                    {{ __('Page') }} {{ $paginator->currentPage() }}@if (! $paginator instanceof \Illuminate\Pagination\Paginator) / {{ $paginator->lastPage() }}@endif
                </span>

                @if ($paginator->hasMorePages())
                    <button type="button" dusk="nextPage" wire:click="nextPage('{{ $paginator->getPageName() }}')"
                            wire:loading.attr="disabled" rel="next" class="btn btn-ghost btn-sm">
                        {!! __('pagination.next') !!}
                    </button>
                @else
                    <span class="btn btn-ghost btn-sm" aria-disabled="true" style="opacity:.5;pointer-events:none">
                        {!! __('pagination.next') !!}
                    </span>
                @endif
            </div>
        </nav>
    @endif
</div>

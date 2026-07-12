@props(['title', 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-start justify-between gap-4 mb-6']) }}>
    <div class="min-w-0">
        <h2 class="text-lg font-semibold tracking-tight">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-0.5 text-sm" style="color:var(--muted)">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex items-center gap-2">{{ $actions }}</div>
    @endisset
</div>

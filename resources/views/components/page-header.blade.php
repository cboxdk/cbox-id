@props(['title', 'subtitle' => null, 'eyebrow' => null])

{{-- Cbox page header — display-font title, optional mono-caps eyebrow, muted
     description, and right-aligned actions. Matches the raw .cbx-page-header
     markup used across the console so component- and inline-based pages align. --}}
<div {{ $attributes->merge(['class' => 'cbx-page-header mb-6']) }}>
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="cbx-page-eyebrow">{{ $eyebrow }}</p>
        @endif
        <h1 class="cbx-page-title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="cbx-page-desc">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex items-center gap-2">{{ $actions }}</div>
    @endisset
</div>

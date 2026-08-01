@props([
    'title',
    'subtitle' => null,
    // Omit it: the area label is read from the nav registry, so the eyebrow always
    // agrees with the sidebar entry that got you here. Pass a string only for a page
    // outside the registry (a wizard, a standalone flow).
    'eyebrow' => null,
    // A HelpTopic (or its string value). Renders the "?" beside the title.
    'help' => null,
])
@php
    use App\Platform\ConsoleLocation;

    $eyebrow = $eyebrow ?? app(ConsoleLocation::class)->areaLabel();
@endphp

{{-- Cbox page header — display-font title, mono-caps eyebrow saying which area you
     are in, muted description, and right-aligned actions. Every console page uses
     this component rather than the raw .cbx-page-header markup, so the eyebrow, the
     explanation affordance and the spacing cannot drift page by page. --}}
<div {{ $attributes->merge(['class' => 'cbx-page-header mb-6']) }}>
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="cbx-page-eyebrow">{{ $eyebrow }}</p>
        @endif
        <h1 class="cbx-page-title">
            {{ $title }}
            @if ($help !== null)
                <x-help :topic="$help" />
            @endif
        </h1>
        @if ($subtitle)
            <p class="cbx-page-desc">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex items-center gap-2">{{ $actions }}</div>
    @endisset
</div>

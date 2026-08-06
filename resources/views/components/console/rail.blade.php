@props([
    // Tier-1 areas. Each: ['key','label','icon','href','active','current'].
    //   active  — this area owns the page being viewed (filled --accent-soft marker)
    //   current — the rail link IS the current page (only true for single-page areas;
    //             for multi-page areas the tier-2 subnav carries aria-current instead)
    'areas',
    'brandHref',
    'brandLabel' => null,
])
{{-- TIER 1 — the shared 64px icon rail, one entry per area (design system:
     guidelines/app-shell). Rendered as a 52px floating pill inside the 64px of flow
     space .cbx-rail-spacer reserves; expands in place to 210px on hover or when
     pinned. Active = filled --accent-soft; left-edge borders are forbidden.

     Requires the shell's x-data to provide: pinned, hover, togglePin(). --}}
{{-- `focusin`/`focusout` alongside the mouse handlers. Without them the rail expanded
     for a pointer and never for a keyboard: tabbing through the primary navigation moved
     focus between unlabelled icons whose only text was a `title` attribute, which never
     appears for a keyboard or a touch user. The toast component already pairs mouse with
     focus for the same reason. --}}
<aside class="cbx-rail hidden lg:flex" :class="{ 'open': pinned || hover }"
       @mouseenter="hover = true" @mouseleave="hover = false"
       @focusin="hover = true" @focusout="hover = false" aria-label="Areas">
    <div class="cbx-rail-hd">
        <a href="{{ $brandHref }}" class="cbx-rail-brand"
           aria-label="{{ $brandLabel ?? config('cbox-id.branding.name', 'Cbox ID') }}"
           title="{{ $brandLabel ?? config('cbox-id.branding.name', 'Cbox ID') }}">
            <svg viewBox="0 0 64 64" role="img" aria-hidden="true"><rect x="2" y="2" width="60" height="60" rx="14" fill="var(--primary)"/><text x="32" y="44" text-anchor="middle" fill="var(--primary-foreground)" font-family="var(--font-display)" font-weight="700" font-size="30" letter-spacing="-0.04em">ID</text></svg>
        </a>
        {{-- Pin toggle, top-right of the rail header — visible only when the rail is
             expanded (pinned or hover-open); tilted when loose, upright + accent pinned. --}}
        <button type="button" class="cbx-pin-btn cbx-pintoggle" :class="{ 'is-pinned': pinned }" @click="togglePin()"
                :title="pinned ? 'Unpin navigation' : 'Pin navigation open'" :aria-pressed="pinned" aria-label="Pin navigation">
            <x-icon name="pin" class="w-[17px] h-[17px]" />
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto" style="scrollbar-width:none">
        @foreach ($areas as $area)
            {{-- wire:navigate: swap the <body> instead of reloading the document. A
                 plain anchor re-parses the whole stylesheet and re-boots Livewire and
                 Alpine on every single sidebar click — the largest perceived-latency
                 cost in the console, paid on the most-used control in it. --}}
            <a href="{{ $area['href'] }}" title="{{ $area['label'] }}" wire:navigate
               class="{{ $area['active'] ? 'cbx-on' : '' }}"
               @if ($area['current'] ?? false) aria-current="page" @endif>
                <x-icon :name="$area['icon']" class="w-[18px] h-[18px]" aria-hidden="true" />
                <span class="lbl">{{ $area['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="cbx-rail-foot">{{ $foot ?? '' }}</div>
</aside>
{{-- Reserves the collapsed floating rail's 52px+insets of flow space so opening
     it as an overlay never pushes the page (widens when pinned/in-flow). --}}
<div class="cbx-rail-spacer" aria-hidden="true"></div>

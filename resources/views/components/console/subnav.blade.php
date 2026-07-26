@props([
    'label',
    // Tier-2 pages of the ACTIVE area. Each: ['href','label','active', 'badge' => ?string].
    'pages',
])
{{-- TIER 2 — the 176px contextual subnav (design system: guidelines/app-shell).
     Per the guideline it is rendered ONLY when the active area has more than one
     page; a single-page area is fully addressed by its rail icon. Callers gate on
     count($pages) > 1 — this component does not render itself conditionally, so a
     caller can never accidentally show an empty column.

     Requires the shell's x-data to provide: subnav (bool). --}}
<aside class="cbx-subnav hidden lg:flex" :class="{ 'collapsed': subnav }">
    <div class="cbx-strip" @click="subnav=false" title="Expand">
        <span class="vlabel">{{ $label }}</span>
        <x-icon name="chevron" class="w-3.5 h-3.5" style="transform:rotate(-90deg)" />
    </div>
    <div class="cbx-subnav-hd">
        <span>{{ $label }}</span>
        <button type="button" class="cbx-subnav-toggle" @click="subnav=true" title="Collapse (⌘.)" aria-label="Collapse subnav">
            <x-icon name="chevron" class="w-4 h-4" style="transform:rotate(90deg)" />
        </button>
    </div>
    <nav aria-label="{{ $label }}">
        @foreach ($pages as $page)
            {{-- wire:navigate — see the rail. Moving between pages of the SAME area is
                 the most frequent navigation in the console; a full document load here
                 re-parses every stylesheet and re-boots Livewire and Alpine to change
                 one column of content. --}}
            <a href="{{ $page['href'] }}" wire:navigate class="{{ $page['active'] ? 'cbx-on' : '' }}"
               @if ($page['active']) aria-current="page" @endif>
                <span>{{ $page['label'] }}</span>
                @if (($page['badge'] ?? null) !== null)
                    {{-- A GLYPH, not the word: at the guideline's 176px the spelled-out
                         "ENTERPRISE" ate 64px and truncated "Single sign-on" /
                         "Outbound sync". The lock costs 14px and keeps every label whole;
                         the wording still reaches screen readers and the tooltip, and the
                         mobile sheet (which has the room) keeps the full text. --}}
                    <span class="cnt shrink-0" style="display:inline-flex;align-items:center;color:var(--primary)" title="{{ $page['badge'] }}">
                        <x-icon name="lock" class="w-3.5 h-3.5" aria-hidden="true" />
                        <span class="sr-only">{{ $page['badge'] }}</span>
                    </span>
                @endif
            </a>
        @endforeach
    </nav>
</aside>

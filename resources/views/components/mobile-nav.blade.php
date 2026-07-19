@props([
    'groups',
    'isActive',
    'heading',
    'subheading' => null,
    'initial' => null,
    'logoutRoute',
    'memberName' => null,
    'memberEmail' => null,
    'securityUrl' => null,
])
{{-- Thumb-anchored mobile navigation, shared by every console shell.

     "Rule of thumb": the only always-visible control is a bar pinned to the BOTTOM
     of the viewport, inside the natural thumb arc — not a hamburger stranded in the
     top-right corner. Tapping it raises a bottom sheet that grows UP from the same
     spot, so the grouped nav opens where the thumb already is.

     Relies on the parent shell providing `x-data="{ nav: false }"`. --}}

{{-- Always-visible bottom bar (thumb zone) --}}
<div class="lg:hidden fixed bottom-0 inset-x-0 z-30 flex items-center gap-3 px-4"
     style="height:3.5rem;padding-bottom:env(safe-area-inset-bottom);background:var(--sidebar);border-top:1px solid var(--sidebar-border)">
    @if ($initial)
        <span class="grid place-items-center w-8 h-8 rounded-lg text-sm font-semibold shrink-0" style="background:var(--accent);color:var(--accent-fg)" aria-hidden="true">{{ $initial }}</span>
    @endif
    <span class="min-w-0 flex-1">
        <span class="block text-[13px] font-semibold truncate leading-tight">{{ $heading }}</span>
        @if ($subheading)
            <span class="block text-[11px] leading-tight" style="color:var(--muted-foreground)">{{ $subheading }}</span>
        @endif
    </span>
    <button type="button" @click="nav=true" aria-label="Open menu"
            class="inline-flex items-center gap-1.5 rounded-lg px-3 h-9 text-[13px] font-medium shrink-0"
            style="background:var(--accent-soft);color:var(--accent)">
        <x-icon name="menu" class="w-[18px] h-[18px]" /> Menu
    </button>
</div>

{{-- Bottom sheet — a real modal dialog: backdrop + panel that rises from the bottom.
     A self-contained focus trap (no Alpine Focus plugin needed) moves focus in on
     open, cycles Tab within the panel, locks background scroll, and returns focus to
     the trigger on close. Escape is handled by the shell's window listener. --}}
<div x-show="nav" x-cloak role="dialog" aria-modal="true" aria-label="Navigation"
     class="lg:hidden fixed inset-0 z-40" style="background:color-mix(in oklch, black 45%, transparent)"
     x-data="{
        prevFocus: null,
        onOpen() { this.prevFocus = document.activeElement; document.documentElement.style.overflow = 'hidden'; this.$nextTick(() => this.$refs.closeBtn && this.$refs.closeBtn.focus()); },
        onClose() { document.documentElement.style.overflow = ''; if (this.prevFocus) { this.prevFocus.focus && this.prevFocus.focus(); this.prevFocus = null; } },
        trap(e) {
            if (e.key !== 'Tab') return;
            const f = Array.from(this.$el.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex=\'-1\'])')).filter(el => el.offsetParent !== null);
            if (!f.length) return;
            const first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
     }"
     x-effect="nav ? onOpen() : onClose()"
     @keydown.tab="trap($event)"
     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     @click.self="nav=false">
    <div class="absolute bottom-0 inset-x-0 flex flex-col rounded-t-2xl shadow-2xl"
         style="max-height:82vh;background:var(--sidebar);border-top:1px solid var(--sidebar-border)"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
        {{-- Grab handle + close --}}
        <div class="flex items-center justify-between px-4 pt-3 pb-1 shrink-0">
            <span class="block mx-auto w-9 h-1 rounded-full" style="background:var(--sidebar-border)" aria-hidden="true"></span>
            <button type="button" x-ref="closeBtn" @click="nav=false" aria-label="Close menu" class="absolute right-3 top-3 grid place-items-center w-8 h-8 rounded-lg" style="color:var(--muted-foreground)">
                <x-icon name="close" class="w-[18px] h-[18px]" />
            </button>
        </div>

        @if (trim($slot) !== '')
            <div class="px-3 pt-1 pb-2 shrink-0" style="border-bottom:1px solid var(--sidebar-border)">{{ $slot }}</div>
        @endif

        <nav class="flex-1 overflow-y-auto px-3 py-2 space-y-3" aria-label="Navigation">
            @foreach ($groups as $group)
                <div class="space-y-0.5">
                    <p class="cbx-nav-group flex items-center gap-2 px-2 pb-1 text-[11px] font-semibold uppercase tracking-wide" style="color:var(--faint)">
                        <x-icon :name="$group['icon']" class="w-3.5 h-3.5" aria-hidden="true" /> {{ $group['label'] }}
                    </p>
                    @foreach ($group['pages'] as $page)
                        <a href="{{ route($page['route']) }}" @click="nav=false" class="nav-link {{ $isActive($page['route']) ? 'is-active' : '' }}"
                           @if ($isActive($page['route'])) aria-current="page" @endif>{{ $page['label'] }}</a>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <div class="px-3 py-3 space-y-0.5 shrink-0" style="border-top:1px solid var(--sidebar-border);padding-bottom:calc(0.75rem + env(safe-area-inset-bottom))">
            @if ($memberName || $memberEmail)
                <div class="px-2 pb-1 min-w-0">
                    <p class="text-[13px] font-medium truncate">{{ $memberName ?? $memberEmail }}</p>
                    @if ($memberEmail)<p class="text-[11px] truncate" style="color:var(--muted-foreground)">{{ $memberEmail }}</p>@endif
                </div>
            @endif
            @if ($securityUrl)
                <a href="{{ $securityUrl }}" class="nav-link w-full"><x-icon name="shield-check" class="w-[1.15rem] h-[1.15rem]" /> Profile &amp; security</a>
            @endif
            <button type="button" data-theme-toggle class="nav-link w-full"><x-icon name="moon" class="w-[1.15rem] h-[1.15rem]" /> Toggle theme</button>
            <form method="POST" action="{{ route($logoutRoute) }}">@csrf
                <button type="submit" class="nav-link w-full" style="color:var(--destructive)"><x-icon name="logout" class="w-[1.15rem] h-[1.15rem]" /> Sign out</button>
            </form>
        </div>
    </div>
</div>

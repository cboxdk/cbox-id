@props(['title' => null])
<!DOCTYPE html>
<html lang="en" class="h-full {{ request()->cookie('cbox-nav-pinned') === '1' ? 'cbx-nav-pinned' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <title>{{ $title ? $title.' · '.config('cbox-id.branding.name', 'Cbox ID') : config('cbox-id.branding.name', 'Cbox ID') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full" style="background:var(--background);color:var(--foreground)">
@php
    // Support-impersonation banner — unmissable, on every authenticated page.
    $impersonation = app(\App\Platform\Impersonation::class)->active();
    $impersonationEmail = $impersonation === null
        ? null
        : app(\Cbox\Id\Identity\Contracts\Subjects::class)->find($impersonation['subject'])?->email;
@endphp
@if ($impersonation !== null)
    <div role="alert"
         style="position:sticky;top:0;z-index:80;width:100%;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:0.75rem;padding:0.6rem 1rem;background:var(--destructive);color:var(--destructive-foreground);font-size:0.85rem;font-weight:600">
        <span><span aria-hidden="true">⚠</span>
            You are impersonating {{ $impersonationEmail ?? $impersonation['subject'] }} for support. Everything you do is logged.
            @if ($impersonation['reason'] !== null)<span style="font-weight:400;opacity:0.9">(reason: {{ $impersonation['reason'] }})</span>@endif
        </span>
        <form method="POST" action="{{ route('impersonation.exit') }}">@csrf
            <button type="submit" style="border:1px solid rgba(255,255,255,0.7);border-radius:6px;padding:3px 12px;background:transparent;color:inherit;font-weight:600;cursor:pointer">Exit impersonation</button>
        </form>
    </div>
@endif

@php
    // ── Two-tier navigation IA. TIER 1 = areas; TIER 2 = an area's pages (shown
    // only when the area has more than one page). The nav is sourced from the shared
    // console-kit registry (App\Providers\ConsoleServiceProvider seeds the defaults),
    // so an installed plugin's areas/pages appear here with no edit to this layout.
    //
    // A page's console-kit `feature` is a hard presence gate — hidden unless the
    // feature is active. The entitlement SOFT-lock (SSO/SCIM shown, but badged when
    // the org isn't entitled) is a separate app gate, keyed by route below.
    $entitlementFeature = [
        'connections' => 'sso', 'sso-providers' => 'sso',
        'directories' => 'scim', 'provisioning' => 'scim',
    ];

    $areas = collect(\Cbox\Console\Kit\Facades\Console::nav()->areas())
        ->map(fn ($area): array => [
            'key' => $area->key,
            'label' => $area->label,
            'icon' => $area->icon,
            'pages' => collect($area->pages())
                ->reject(fn ($p): bool => $p->feature !== null && ! \Cbox\Console\Kit\Facades\Console::featureActive($p->feature))
                ->map(fn ($p): array => [
                    'route' => $p->route,
                    'label' => $p->label,
                    'feature' => $entitlementFeature[$p->route] ?? null,
                ])->values()->all(),
        ])
        ->reject(fn (array $a): bool => $a['pages'] === [])
        ->values()->all();

    $entitlements = app(\App\Platform\Entitlements::class);
    $isLocked = fn (array $page): bool => isset($page['feature']) && ! $entitlements->entitledOrgFeature($page['feature']);
    $routeActive = fn (string $route): bool => request()->routeIs($route.'*');

    $activeArea = collect($areas)->first(
        fn (array $a): bool => collect($a['pages'])->contains(fn (array $p): bool => $routeActive($p['route']))
    ) ?? $areas[0];
    $showSubnav = count($activeArea['pages']) > 1;

    // Organizations the signed-in subject belongs to, for the topbar switcher.
    $myOrgs = collect();
    if ($me->check()) {
        $orgRepo = app(\Cbox\Id\Organization\Contracts\Organizations::class);
        $myOrgs = app(\Cbox\Id\Organization\Contracts\Memberships::class)->forUser($me->id())
            ->map(fn ($m) => (object) ['id' => $m->organization_id, 'role' => $m->role ?? null, 'name' => $orgRepo->find($m->organization_id)?->name])
            ->filter(fn ($o) => $o->name !== null)->values();
    }
    $activeOrgId = $me->organization()?->id;
    $canSwitch = $myOrgs->count() > 1;
    $orgInitial = strtoupper(substr($me->organization()?->name ?? 'C', 0, 1));
    $userInitial = strtoupper(substr($me->name(), 0, 1));
@endphp

<a href="#main-content" class="skip-link">Skip to content</a>

<div class="flex h-full" x-data="{
        pinned: {{ request()->cookie('cbox-nav-pinned') === '1' ? 'true' : 'false' }},
        subnav: localStorage.getItem('cbox-subnav-collapsed') === '1',
        mobile: false, account: false, org: false, hover: false,
        togglePin() { this.pinned = !this.pinned; document.documentElement.classList.toggle('cbx-nav-pinned', this.pinned); document.cookie = 'cbox-nav-pinned=' + (this.pinned ? '1' : '0') + ';path=/;max-age=31536000;samesite=lax'; },
        toggleSubnav() { this.subnav = !this.subnav; localStorage.setItem('cbox-subnav-collapsed', this.subnav ? '1' : '0'); }
     }"
     @keydown.escape.window="mobile=false;account=false;org=false"
     @keydown.window.cmd.period.prevent="toggleSubnav()" @keydown.window.ctrl.period.prevent="toggleSubnav()">

    {{-- ═══ TIER 1 — icon rail (desktop). 52px icons; expands in-flow to 210px when
         pinned via the always-visible toggle in the rail foot. ═══ --}}
    <aside class="cbx-rail hidden lg:flex" :class="{ 'open': pinned || hover }"
           @mouseenter="hover = true" @mouseleave="hover = false" aria-label="Areas">
        <div class="cbx-rail-hd">
            <a href="{{ route('dashboard') }}" class="cbx-rail-brand" aria-label="{{ config('cbox-id.branding.name', 'Cbox ID') }}" title="{{ config('cbox-id.branding.name', 'Cbox ID') }}">
                <svg viewBox="0 0 64 64" role="img" aria-hidden="true"><rect x="2" y="2" width="60" height="60" rx="14" fill="var(--primary)"/><text x="32" y="44" text-anchor="middle" fill="var(--primary-foreground)" font-family="var(--font-display)" font-weight="700" font-size="30" letter-spacing="-0.04em">ID</text></svg>
            </a>
        </div>

        <nav class="flex-1 overflow-y-auto" style="scrollbar-width:none">
            @foreach ($areas as $area)
                <a href="{{ route($area['pages'][0]['route']) }}" title="{{ $area['label'] }}"
                   class="{{ $area['key'] === $activeArea['key'] ? 'cbx-on' : '' }}">
                    <x-icon :name="$area['icon']" class="w-[18px] h-[18px]" aria-hidden="true" />
                    <span class="lbl">{{ $area['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="cbx-rail-foot">
            <button type="button" class="cbx-railitem" @click="togglePin()" :title="pinned ? 'Collapse navigation' : 'Expand navigation'" aria-label="Toggle navigation width">
                <span class="cbx-navtoggle-ico"><x-icon name="chevron" class="w-[18px] h-[18px]" /></span>
                <span class="lbl">Collapse</span>
            </button>
            <button type="button" class="cbx-railitem" @click="account=!account" title="{{ $me->name() }}" aria-haspopup="true" :aria-expanded="account">
                <span class="cbx-avatar" aria-hidden="true">{{ $userInitial }}</span>
                <span class="lbl" style="overflow:hidden;text-overflow:ellipsis">{{ $me->name() }}</span>
            </button>
            <div x-show="account" x-transition.opacity.duration.150ms @click.outside="account=false" x-cloak
                 class="cbx-panel" style="position:absolute;bottom:calc(100% + 8px);left:0;min-width:230px;z-index:75;box-shadow:var(--shadow-popover);padding:6px">
                <div style="padding:8px 10px;border-bottom:1px solid var(--border);margin-bottom:4px">
                    <p style="font-size:13px;font-weight:600;margin:0" class="truncate">{{ $me->name() }}</p>
                    <p style="font-size:12px;color:var(--muted-foreground);margin:2px 0 0" class="truncate">{{ $me->email() }}</p>
                </div>
                <button type="button" data-theme-toggle class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;font-size:13px">
                    <x-icon name="moon" class="w-4 h-4" /> Toggle theme
                </button>
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button type="submit" class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;font-size:13px;color:var(--destructive)">
                        <x-icon name="logout" class="w-4 h-4" /> Sign out
                    </button>
                </form>
            </div>
        </div>
    </aside>
    {{-- Reserves the collapsed floating rail's 52px+insets of flow space so opening
         it as an overlay never pushes the page (hidden when pinned/in-flow). --}}
    <div class="cbx-rail-spacer" aria-hidden="true"></div>

    {{-- ═══ TIER 2 — contextual subnav (desktop, multi-page areas only) ═══ --}}
    @if ($showSubnav)
        <aside class="cbx-subnav hidden lg:flex" :class="{ 'collapsed': subnav }">
            <div class="cbx-strip" @click="subnav=false" title="Expand">
                <span class="vlabel">{{ $activeArea['label'] }}</span>
                <x-icon name="chevron" class="w-3.5 h-3.5" style="transform:rotate(-90deg)" />
            </div>
            <div class="cbx-subnav-hd">
                <span>{{ $activeArea['label'] }}</span>
                <button type="button" class="cbx-subnav-toggle" @click="subnav=true" title="Collapse (⌘.)" aria-label="Collapse subnav">
                    <x-icon name="chevron" class="w-4 h-4" style="transform:rotate(90deg)" />
                </button>
            </div>
            <nav aria-label="{{ $activeArea['label'] }}">
                @foreach ($activeArea['pages'] as $page)
                    <a href="{{ route($page['route']) }}" class="{{ $routeActive($page['route']) ? 'cbx-on' : '' }}">
                        <span>{{ $page['label'] }}</span>
                        @if ($isLocked($page))
                            <span class="cnt" style="text-transform:uppercase;letter-spacing:0.04em;font-size:9.5px;font-weight:600;color:var(--primary)">Enterprise</span>
                        @endif
                    </a>
                @endforeach
            </nav>
        </aside>
    @endif

    {{-- ═══ Mobile drawer ═══ --}}
    <div class="lg:hidden" x-cloak>
        <div x-show="mobile" x-transition.opacity class="fixed inset-0 z-40" style="background:rgb(0 0 0 / 0.5)" @click="mobile=false" aria-hidden="true"></div>
        <div x-show="mobile"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
             class="fixed inset-y-0 left-0 z-50 w-72 max-w-[85%] flex flex-col" style="background:var(--sidebar);border-right:1px solid var(--sidebar-border)"
             role="dialog" aria-modal="true" aria-label="Navigation">
            <div class="cbx-sidebar-brand" style="justify-content:space-between">
                <a href="{{ route('dashboard') }}"><x-brand /></a>
                <button type="button" @click="mobile=false" class="cbx-subnav-toggle" aria-label="Close navigation"><x-icon name="close" class="w-[18px] h-[18px]" /></button>
            </div>
            <nav class="cbx-nav" aria-label="Primary">
                @foreach ($areas as $area)
                    <p class="cbx-nav-group">{{ $area['label'] }}</p>
                    @foreach ($area['pages'] as $page)
                        <a href="{{ route($page['route']) }}" class="nav-link" @click="mobile=false" @if ($routeActive($page['route'])) aria-current="page" @endif>
                            <x-icon :name="$area['icon']" class="w-[1.15rem] h-[1.15rem]" aria-hidden="true" />
                            {{ $page['label'] }}
                            @if ($isLocked($page))<span class="ml-auto" style="font-size:0.6rem;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:var(--primary)">Enterprise</span>@endif
                        </a>
                    @endforeach
                @endforeach
            </nav>
            <div class="p-3" style="border-top:1px solid var(--sidebar-border)">
                <div class="flex items-center gap-2.5 px-1 mb-2">
                    <span class="cbx-avatar" style="width:2rem;height:2rem" aria-hidden="true">{{ $userInitial }}</span>
                    <div class="min-w-0"><p class="text-sm font-medium truncate leading-tight">{{ $me->name() }}</p><p class="text-xs truncate" style="color:var(--muted-foreground)">{{ $me->email() }}</p></div>
                </div>
                <button type="button" data-theme-toggle class="nav-link w-full"><x-icon name="moon" class="w-[1.15rem] h-[1.15rem]" /> Toggle theme</button>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="nav-link w-full"><x-icon name="logout" class="w-[1.15rem] h-[1.15rem]" /> Sign out</button></form>
            </div>
        </div>
    </div>

    {{-- ═══ Main column ═══ --}}
    <div class="flex flex-col min-w-0 flex-1">
        <header class="cbx-topbar">
            <div class="flex items-center gap-2 min-w-0">
                <button type="button" @click="mobile=true" class="cbx-subnav-toggle lg:hidden" aria-label="Open navigation"><x-icon name="menu" class="w-[18px] h-[18px]" /></button>

                {{-- Org context crumb + switcher (Linear/Notion style). --}}
                <div class="relative">
                    <button type="button" class="flex items-center gap-2 rounded-lg px-2 py-1.5 {{ $canSwitch ? '' : 'pointer-events-none' }}"
                            style="transition:background-color var(--dur-hover) var(--ease)" @if ($canSwitch) @click="org=!org" onmouseover="this.style.background='var(--secondary)'" onmouseout="this.style.background='transparent'" :aria-expanded="org" aria-haspopup="true" @endif>
                        <span class="grid place-items-center rounded-md text-[11px] font-bold shrink-0" style="width:26px;height:26px;background:var(--accent-soft);color:var(--primary)">{{ $orgInitial }}</span>
                        <span class="min-w-0 text-left hidden sm:block">
                            <span class="block text-[13px] font-semibold truncate leading-tight">{{ $me->organization()?->name ?? 'No organization' }}</span>
                            <span class="block text-[11px] truncate leading-tight" style="color:var(--muted-foreground)">{{ $me->role() ? ucfirst($me->role()) : 'Member' }}</span>
                        </span>
                        @if ($canSwitch)<x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--muted-foreground)" aria-hidden="true" />@endif
                    </button>
                    @if ($canSwitch)
                        <div x-show="org" x-transition.opacity.duration.150ms @click.outside="org=false" x-cloak
                             class="cbx-panel" style="position:absolute;top:calc(100% + 6px);left:0;min-width:260px;z-index:40;box-shadow:var(--shadow-popover);padding:6px">
                            <p class="cbx-nav-group" style="padding:6px 10px 4px">Switch organization</p>
                            @foreach ($myOrgs as $o)
                                <form method="POST" action="{{ route('organization.switch') }}">@csrf
                                    <input type="hidden" name="organization" value="{{ $o->id }}">
                                    <button type="submit" class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;{{ $o->id === $activeOrgId ? 'background:var(--secondary)' : '' }}">
                                        <span class="grid place-items-center rounded-md text-[10px] font-bold shrink-0" style="width:24px;height:24px;background:var(--accent-soft);color:var(--primary)">{{ strtoupper(substr($o->name, 0, 1)) }}</span>
                                        <span class="min-w-0 flex-1 text-left"><span class="block text-[13px] truncate">{{ $o->name }}</span><span class="block text-[11px] truncate" style="color:var(--muted-foreground)">{{ $o->role ? ucfirst($o->role) : 'Member' }}</span></span>
                                        @if ($o->id === $activeOrgId)<x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" />@endif
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" class="cbx-search hidden md:inline-flex" style="width:160px" aria-label="Search">
                    <x-icon name="search" class="w-4 h-4" />
                    <span class="label">Search…</span>
                    <kbd>⌘K</kbd>
                </button>
                <button type="button" data-theme-toggle class="cbx-subnav-toggle" aria-label="Toggle theme" title="Toggle theme"><x-icon name="sun" class="w-[18px] h-[18px]" /></button>
            </div>
        </header>

        @if (session('status'))
            <div role="status" aria-live="polite" class="mx-6 mt-4 rounded-lg px-4 py-3 text-sm"
                 style="background:var(--success-soft);color:var(--success);border:1px solid color-mix(in oklch,var(--success) 20%,transparent)">{{ session('status') }}</div>
        @endif

        <main id="main-content" class="flex-1 overflow-y-auto canvas-gradient">
            <div class="p-6 lg:p-8 mx-auto w-full" style="max-width:72rem">{{ $slot }}</div>
        </main>
    </div>
</div>

</body>
</html>

@props(['title' => null])
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
        'connections' => 'sso',
        'directories' => 'scim', 'provisioning' => 'scim',
    ];

    // Role gate: a plain member sees only their overview (with the app launcher) and
    // their own account. Everything else is organization administration, shown to
    // admins/owners. Plugin areas (billing, connectors, …) are admin surfaces too.
    //
    // `identity-platform` is exempt, and deliberately so rather than by oversight: the
    // membership that places an account member in their own account's organization
    // carries MembershipRole::Member on purpose, so an account OWNER is not an org admin
    // there and this gate would hide their own projects and billing from them. That area
    // already gates every one of its pages on the MembershipRole (see ConsoleServiceProvider),
    // which is the authority for those capabilities — the org role has nothing to say
    // about them.
    // The platform areas are exempt for the opposite reason to `identity-platform`: this
    // gate asks whether you administer the ORGANIZATION you are currently acting for, and
    // an operator's authority has nothing to do with that. The person who runs the install
    // is routinely a plain member of the customer they happen to be looking at — the
    // operator running cboxid.com is a member of Cbox's own organization and nothing more —
    // so leaving them to this gate hides the platform section from exactly the people it
    // exists for. Their own `platform.operator` feature is the stronger gate and it has
    // already run: an area survives to here only if its pages did.
    $isConsoleAdmin = \Cbox\Console\Kit\Facades\Console::context()->isAdmin();
    $memberAreas = ['overview', 'account', 'identity-platform', 'platform', 'platform-insights', 'platform-admin'];

    $areas = collect(\Cbox\Console\Kit\Facades\Console::nav()->areas())
        ->reject(fn ($area): bool => ! $isConsoleAdmin && ! in_array($area->key, $memberAreas, true))
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

    // Shape for the shared shell components. A single-page area needs no tier 2,
    // so its rail icon is itself the current page and carries aria-current.
    $railAreas = collect($areas)->map(fn (array $a): array => [
        'key' => $a['key'],
        'label' => $a['label'],
        'icon' => $a['icon'],
        'href' => route($a['pages'][0]['route']),
        'active' => $a['key'] === $activeArea['key'],
        'current' => $a['key'] === $activeArea['key'] && count($a['pages']) === 1,
    ])->all();
    $subnavPages = collect($activeArea['pages'])->map(fn (array $p): array => [
        'href' => route($p['route']),
        'label' => $p['label'],
        'active' => $routeActive($p['route']),
        'badge' => $isLocked($p) ? 'Enterprise' : null,
    ])->all();

    // Organizations the signed-in subject belongs to, for the topbar switcher.
    $myOrgs = collect();
    if ($me->check()) {
        $memberships = app(\Cbox\Id\Organization\Contracts\Memberships::class)->forUser($me->id());
        // The switcher only renders for >1 membership, so only then do we resolve org
        // names — and in a single batch (findMany) rather than a query per membership.
        // This runs on EVERY authenticated console page, so the single-org fast path
        // (the common case) now costs zero org queries.
        if ($memberships->count() > 1) {
            $orgsById = app(\Cbox\Id\Organization\Contracts\Organizations::class)
                ->findMany($memberships->pluck('organization_id')->all());
            $myOrgs = $memberships
                ->map(fn ($m) => (object) ['id' => $m->organization_id, 'role' => $m->role ?? null, 'name' => ($orgsById[$m->organization_id] ?? null)?->name])
                ->filter(fn ($o) => $o->name !== null)->values();
        }
    }
    // WHICH SECTION THE TAB IS SHOWING, and null for the customer-facing console.
    //
    // An operator works with many tabs open, and half the platform pages share a name with
    // a page about the operator's OWN organization: "Usage" is this install's traffic in
    // one and one customer's bill in the other, "Members" is the staff list in one and a
    // team in the other. The platform section had its own shell, and that shell put the
    // word in the title; folding it into the one console dropped it, and the tab strip
    // stopped distinguishing the whole install from one customer on it.
    //
    // Keyed on the AREA rather than on the route name, so a page added to the section
    // inherits it. One word for all three areas on purpose: "Insights" or "Administration"
    // in a tab title says nothing about whose data is behind it, which is the entire
    // question being answered here.
    $platformAreas = ['platform', 'platform-insights', 'platform-admin'];
    $section = in_array($activeArea['key'], $platformAreas, true) ? 'Platform' : null;

    $activeOrgId = $me->organization()?->id;
    $canSwitch = $myOrgs->count() > 1;
    $orgInitial = strtoupper(substr($me->organization()?->name ?? 'C', 0, 1));
    $userInitial = strtoupper(substr($me->name(), 0, 1));

    // ── Operator chrome. The target-environment selector came in with the platform
    // section and belongs to it: operators stand above every environment, and switching
    // just repoints where the platform pages read and provision.
    //
    // Guarded on the SCOPE, never on which layout rendered. The platform shell used to BE
    // the guard — the control existed only in a file only operators reached — and a shell
    // is not an authorization check. Now that there is one shell it could not be anyway.
    //
    // Two queries for the whole list even though this renders on EVERY console page: the
    // work is skipped outright for the overwhelming majority of sessions (nobody is an
    // operator), and for the few that aren't, the owner names come from one batched
    // lineage lookup rather than a query per environment.
    $isOperator = app(\App\Platform\Console\ConsoleScope::class)->isPlatformOperator();
    $environments = collect();
    $activeEnvId = null;
    $activeEnv = null;
    $canSwitchEnv = false;
    $lineage = [];

    if ($isOperator) {
        $ctx = app(\Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext::class);
        $activeEnvId = $ctx->current()?->environmentKey();
        // `project_id` comes along because the switcher names the OWNER, not just the
        // stage: "Production" is a name half the customers on an install will have, and a
        // control that says only that tells an operator nothing about whose estate their
        // next click lands in. The owner is reached THROUGH the project —
        // `environments.account_id` was the shortcut, and it is gone.
        $environments = $ctx->withoutScope(fn () => \Cbox\Id\Organization\Models\Environment::query()
            ->orderBy('created_at')->get(['id', 'name', 'slug', 'project_id']));
        $lineage = app(\App\Platform\Console\EnvironmentLineages::class)->for($environments);
        $activeEnv = $environments->firstWhere('id', $activeEnvId);
        $canSwitchEnv = $environments->count() > 1;
    }
@endphp

<!DOCTYPE html>
<html lang="en"{!! \App\Platform\Theme::attribute() !!} class="h-full {{ request()->cookie('cbox-nav-pinned') === '1' ? 'cbx-nav-pinned' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Root paths, not /brand/*. Icon harvesters — password managers, link
         unfurlers, browsers restoring a tab before the HTML parses — probe
         /favicon.ico and /apple-touch-icon.png directly and never read these tags.
         Laravel's skeleton ships an EMPTY favicon.ico, so that probe returned a
         perfectly valid 200 with zero bytes for the life of the project. --}}
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b0b0b" media="(prefers-color-scheme: dark)">
    <title>{{ ($title ? $title.' · ' : '').($section ? $section.' · ' : '').config('cbox-id.branding.name', 'Cbox ID') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Per-tenant console branding when the whitelabel plugin is installed; inert otherwise. --}}
    @consoleBrandingStyle
</head>
<body class="h-full" style="background:var(--background);color:var(--foreground)">
<x-sandbox-banner />
<x-impersonation-banner />


<a href="#main-content" class="skip-link">Skip to content</a>

<div class="flex h-full" x-data="{
        pinned: {{ request()->cookie('cbox-nav-pinned') === '1' ? 'true' : 'false' }},
        subnav: localStorage.getItem('cbox-subnav-collapsed') === '1',
        mobile: false, account: false, org: false, env: false, hover: false,
        togglePin() { this.pinned = !this.pinned; document.documentElement.classList.toggle('cbx-nav-pinned', this.pinned); document.cookie = 'cbox-nav-pinned=' + (this.pinned ? '1' : '0') + ';path=/;max-age=31536000;samesite=lax'; },
        toggleSubnav() { this.subnav = !this.subnav; localStorage.setItem('cbox-subnav-collapsed', this.subnav ? '1' : '0'); }
     }"
     @keydown.escape.window="mobile=false;account=false;org=false;env=false"
     @keydown.window.cmd.period.prevent="toggleSubnav()" @keydown.window.ctrl.period.prevent="toggleSubnav()">

    {{-- ═══ TIER 1 — icon rail (desktop) ═══ --}}
    <x-console.rail :areas="$railAreas" :brand-href="route('dashboard')">
        <x-slot:foot>
            <x-console.account-menu :name="$me->name()" :email="$me->email()" :initial="$userInitial" logout-route="logout">
                <a href="{{ route('account') }}" class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;font-size:13px">
                    <x-icon name="key" class="w-4 h-4" /> My account
                </a>
                <a href="{{ route('accounts') }}" class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;font-size:13px">
                    <x-icon name="refresh" class="w-4 h-4" /> Switch account
                </a>
            </x-console.account-menu>
        </x-slot:foot>
    </x-console.rail>

    {{-- ═══ TIER 2 — contextual subnav (desktop, multi-page areas only) ═══ --}}
    @if (count($subnavPages) > 1)
        <x-console.subnav :label="$activeArea['label']" :pages="$subnavPages" />
    @endif

    {{-- ═══ Mobile drawer ═══ --}}
    <div class="lg:hidden" x-cloak>
        <div x-show="mobile" x-transition.opacity class="fixed inset-0 z-40" style="background:var(--overlay)" @click="mobile=false" aria-hidden="true"></div>
        {{-- Self-contained focus trap (the Alpine Focus plugin / x-trap is NOT loaded in
             this app, so the same hand-rolled pattern as components/mobile-nav.blade.php and
             components/confirm-delete.blade.php is used): on open, save the active element,
             move focus into the drawer and lock background scroll; cycle Tab within the panel;
             restore focus + unlock scroll on close. Esc is handled by the shell's window
             listener, which flips `mobile` and so triggers onClose() through x-effect. --}}
        <div x-show="mobile"
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
             x-effect="mobile ? onOpen() : onClose()"
             @keydown.tab="trap($event)"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
             class="fixed inset-y-0 left-0 z-50 w-72 max-w-[85%] flex flex-col" style="background:var(--sidebar);border-right:1px solid var(--sidebar-border)"
             role="dialog" aria-modal="true" aria-label="Navigation">
            <div class="cbx-sidebar-brand" style="justify-content:space-between">
                <a href="{{ route('dashboard') }}"><x-brand /></a>
                <button type="button" x-ref="closeBtn" @click="mobile=false" class="cbx-subnav-toggle" aria-label="Close navigation"><x-icon name="close" class="w-[18px] h-[18px]" /></button>
            </div>
            <nav class="cbx-nav" aria-label="Primary">
                @foreach ($areas as $area)
                    <p class="cbx-nav-group">
                        <x-icon :name="$area['icon']" class="w-[0.95rem] h-[0.95rem]" aria-hidden="true" />
                        {{ $area['label'] }}
                    </p>
                    @foreach ($area['pages'] as $page)
                        {{-- wire:navigate — see x-console.rail.

                             The icon sits on the GROUP, not on every page under it: an
                             area's icon is the same glyph for all of its pages, so
                             repeating it three times below one heading was three
                             identical marks that distinguished nothing and ate the
                             width the labels needed on a 375px screen. --}}
                        <a href="{{ route($page['route']) }}" wire:navigate class="nav-link is-nested" @click="mobile=false" @if ($routeActive($page['route'])) aria-current="page" @endif>
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
                    <button type="button" class="cbx-switcher-item flex items-center gap-2 rounded-lg px-2 py-1.5 {{ $canSwitch ? '' : 'pointer-events-none' }}"
                            style="transition:background-color var(--dur-hover) var(--ease)" @if ($canSwitch) @click="org=!org" :aria-expanded="org" aria-haspopup="true" @endif>
                        <span class="grid place-items-center rounded-md text-[11px] font-bold shrink-0" style="width:26px;height:26px;background:var(--accent-soft);color:var(--primary)">{{ $orgInitial }}</span>
                        <span class="min-w-0 text-left hidden sm:block">
                            <span class="block text-[13px] font-semibold truncate leading-tight">{{ $me->organization()?->name ?? 'No organization' }}</span>
                            <span class="block text-[11px] truncate leading-tight" style="color:var(--muted-foreground)">{{ $me->role()?->label() ?? 'Member' }}</span>
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
                                        <span class="min-w-0 flex-1 text-left"><span class="block text-[13px] truncate">{{ $o->name }}</span><span class="block text-[11px] truncate" style="color:var(--muted-foreground)">{{ $o->role?->label() ?? 'Member' }}</span></span>
                                        @if ($o->id === $activeOrgId)<x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" />@endif
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Target environment. Only for whoever runs the deployment, and only ever
                     beside the organization it belongs to — an operator acting on a tenant
                     should be able to see, without leaving the page, which tenant that is.
                     The retired staff console said "Operator" here instead, which read as a
                     different product rather than as a wider permission. --}}
                @if ($isOperator)
                    <span style="color:var(--faint)" aria-hidden="true">/</span>
                    <div class="relative min-w-0">
                        <button type="button" class="cbx-switcher-item flex items-center gap-2 rounded-lg px-2 py-1.5 {{ $canSwitchEnv ? '' : 'pointer-events-none' }}"
                                style="transition:background-color var(--dur-hover) var(--ease)" @if ($canSwitchEnv) @click="env=!env" :aria-expanded="env" aria-haspopup="true" @endif>
                            <x-icon name="layers" class="w-4 h-4 shrink-0" style="color:var(--primary)" aria-hidden="true" />
                            <span class="min-w-0 text-left hidden sm:block">
                                <span class="block text-[10px] font-medium uppercase tracking-wide leading-tight" style="color:var(--muted-foreground)">Target environment</span>
                                <span class="block text-[13px] font-semibold truncate leading-tight">
                                    {{ $activeEnv !== null && isset($lineage[$activeEnv->id])
                                        ? $lineage[$activeEnv->id]->qualify($activeEnv->name)
                                        : ($activeEnv?->name ?? 'None yet') }}
                                </span>
                            </span>
                            @if ($canSwitchEnv)<x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--muted-foreground)" aria-hidden="true" />@endif
                        </button>
                        @if ($canSwitchEnv)
                            <div x-show="env" x-transition.opacity.duration.150ms @click.outside="env=false" x-cloak
                                 class="cbx-panel" style="position:absolute;top:calc(100% + 6px);left:0;min-width:260px;z-index:40;box-shadow:var(--shadow-popover);padding:6px">
                                <p class="cbx-nav-group" style="padding:6px 10px 4px">Switch target</p>
                                @foreach ($environments as $environment)
                                    <form method="POST" action="{{ route('platform.environment.switch') }}">@csrf
                                        <input type="hidden" name="environment" value="{{ $environment->id }}">
                                        <button type="submit" class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;{{ $environment->id === $activeEnvId ? 'background:var(--secondary)' : '' }}">
                                            <x-icon name="layers" class="w-3.5 h-3.5 shrink-0" style="color:var(--muted-foreground)" />
                                            <span class="min-w-0 flex-1 text-left"><span class="block text-[13px] truncate">{{ isset($lineage[$environment->id]) ? $lineage[$environment->id]->qualify($environment->name) : $environment->name }}</span><span class="block text-[11px] mono truncate" style="color:var(--muted-foreground)">{{ $environment->slug }}</span></span>
                                            @if ($environment->id === $activeEnvId)<x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" />@endif
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-2">
                {{-- WHAT AUTHORITY YOU ARE HOLDING. The retired platform shell said
                     "Platform operator" under the brand, and it was the only thing on
                     screen that did — on a console where one click suspends a customer.
                     Folding the section into the one console dropped it: the topbar names
                     the ORGANIZATION you act for, which for an operator is their own
                     employer and says nothing about the authority they are wielding over
                     everybody else's.

                     Shown on every console page, not only the platform ones, and that is
                     the point — the authority does not lapse when an operator navigates to
                     a customer-facing page, so neither should the reminder that they have
                     it. --}}
                @if ($isOperator)
                    <span class="hidden sm:inline-flex items-center gap-1.5 rounded-full"
                          style="padding:3px 10px;background:var(--accent-soft);color:var(--primary);font-size:11px;font-weight:600;letter-spacing:0.01em">
                        <x-icon name="lock" class="w-3 h-3" aria-hidden="true" />
                        Platform operator
                    </span>
                @endif
                {{-- No global command palette ships yet — a non-functional ⌘K search
                     box was removed rather than left as a dead affordance. --}}
                <button type="button" data-theme-toggle class="cbx-subnav-toggle" aria-label="Toggle theme" title="Toggle theme"><x-icon name="sun" class="w-[18px] h-[18px]" /></button>
            </div>
        </header>

        <main id="main-content" class="flex-1 overflow-y-auto canvas-gradient">
            <div class="p-6 lg:p-8 mx-auto w-full" style="max-width:72rem">{{ $slot }}</div>
        </main>
    </div>
</div>

    <x-toast />
</body>
</html>

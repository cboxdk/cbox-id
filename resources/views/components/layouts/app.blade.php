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
                    // Precomputed here rather than in the view: the shared mobile nav
                    // renders whatever badge it is handed and asks no questions about
                    // entitlements, which is what lets both shells use it.
                    'badge' => null,
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
        nav: false, account: false, org: false, env: false, hover: false,
        togglePin() { this.pinned = !this.pinned; document.documentElement.classList.toggle('cbx-nav-pinned', this.pinned); document.cookie = 'cbox-nav-pinned=' + (this.pinned ? '1' : '0') + ';path=/;max-age=31536000;samesite=lax'; },
        toggleSubnav() { this.subnav = !this.subnav; localStorage.setItem('cbox-subnav-collapsed', this.subnav ? '1' : '0'); }
     }"
     @keydown.escape.window="nav=false;account=false;org=false;env=false"
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

    {{-- ═══ Mobile navigation ═══

         THE SHARED COMPONENT, not a drawer of this shell's own. It hand-rolled a
         hamburger-and-slide-in-from-the-left panel while `components/mobile-nav` — whose
         own docblock calls it "shared by every console shell" — was used by the
         environment shell alone. So the two planes had two mobile interaction models over
         what are now the SAME page components, and the thumb-zone pattern the shared one
         implements is the house pattern.

         The entitlement badge this shell's drawer carried moved into the component with
         it; the organization switcher stays here, in the slot, because it is genuinely
         this plane's. --}}
    <x-mobile-nav :groups="$areas" :is-active="$routeActive" :heading="$me->organization()?->name ?? 'Console'"
                  :initial="$userInitial" logout-route="logout"
                  :member-name="$me->name()" :member-email="$me->email()">
        @if ($myOrgs->count() > 1)
            <p class="cbx-nav-group px-2 pt-2 pb-1">Switch organization</p>
            <div class="max-h-52 overflow-y-auto space-y-0.5">
                @foreach ($myOrgs as $o)
                    <form method="POST" action="{{ route('organization.switch') }}">
                        @csrf
                        <input type="hidden" name="organization" value="{{ $o->id }}">
                        <button type="submit" class="cbx-row w-full" style="padding:8px 10px;border-radius:8px;gap:10px;{{ $o->id === $me->organizationId() ? 'background:var(--secondary)' : '' }}">
                            <span class="grid place-items-center rounded-md text-[10px] font-bold shrink-0" style="width:24px;height:24px;background:var(--accent-soft);color:var(--primary)">{{ strtoupper(substr($o->name, 0, 1)) }}</span>
                            <span class="min-w-0 flex-1 text-left"><span class="block text-[13px] truncate">{{ $o->name }}</span></span>
                            @if ($o->id === $me->organizationId())<x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" aria-hidden="true" />@endif
                        </button>
                    </form>
                @endforeach
            </div>
        @endif
    </x-mobile-nav>


    {{-- ═══ Main column ═══ --}}
    <div class="flex flex-col min-w-0 flex-1">
        <header class="cbx-topbar">
            <div class="flex items-center gap-2 min-w-0">
                <button type="button" @click="nav=true" class="cbx-subnav-toggle lg:hidden" aria-label="Open navigation"><x-icon name="menu" class="w-[18px] h-[18px]" /></button>

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
                                {{-- TWO DIFFERENT OPERATIONS, and the menu offered only one.

                                     Selecting a row RE-POINTS this console at that
                                     environment while you stay on the operator host, with
                                     operator authority — which is what the platform pages
                                     need, since Customers and Organizations are read
                                     through the pointed environment.

                                     What it did NOT offer is the thing the label most
                                     looks like it means: opening that environment's own
                                     console. That path already existed —
                                     `environment.open` mints a signed handoff to
                                     `{slug}.{base_domain}/admin/handoff`, no second login
                                     — but it was reachable only from Projects, so the
                                     control that reads "switch target" could not go
                                     there and the one that could was on another page.
                                     Both are here now, and each says which it is. --}}
                                <p class="cbx-nav-group" style="padding:6px 10px 4px">Switch target</p>
                                @foreach ($environments as $environment)
                                    <div class="flex items-center gap-1" style="{{ $environment->id === $activeEnvId ? 'background:var(--secondary);border-radius:6px' : '' }}">
                                        <form method="POST" action="{{ route('platform.environment.switch') }}" class="min-w-0 flex-1">@csrf
                                            <input type="hidden" name="environment" value="{{ $environment->id }}">
                                            <button type="submit" class="cbx-row w-full" style="padding:8px 10px;border-radius:6px;gap:10px">
                                                <x-icon name="layers" class="w-3.5 h-3.5 shrink-0" style="color:var(--muted-foreground)" />
                                                <span class="min-w-0 flex-1 text-left"><span class="block text-[13px] truncate">{{ isset($lineage[$environment->id]) ? $lineage[$environment->id]->qualify($environment->name) : $environment->name }}</span><span class="block text-[11px] mono truncate" style="color:var(--muted-foreground)">{{ $environment->slug }}</span></span>
                                                @if ($environment->id === $activeEnvId)<x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" />@endif
                                            </button>
                                        </form>
                                        {{-- Leaves this host for the tenant's own console. Titled rather
                                             than labelled inline: the row is already two lines deep and a
                                             third word would wrap on a narrow window. --}}
                                        <a href="{{ route('environment.open', $environment->id) }}"
                                           class="btn btn-ghost btn-sm shrink-0" style="padding:6px 8px"
                                           title="Open {{ $environment->name }}'s own console"
                                           aria-label="Open {{ $environment->name }}'s own console">↗</a>
                                    </div>
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
            {{-- Kept identical to the environment shell's container — see the note there.
                 One number, two files, and the pages between them are shared. --}}
            <div class="p-6 lg:p-8 mx-auto w-full" style="max-width:72rem">{{ $slot }}</div>
        </main>
    </div>
</div>

    <x-toast />
</body>
</html>

@props(['title' => null, 'width' => '48rem'])
@php
    use App\Platform\Console\ConsoleScope;

    // WHO IS SIGNED IN. Everything identity-shaped here used to fall through the account
    // MEMBER, which is null for a platform operator who buys nothing on the deployment
    // they run — so the topbar read "Workspace / Account", the rail foot read "Account",
    // and the account popover read "Account" with no address under it. On a console where
    // one click suspends a customer, nothing on screen named the session. Only the
    // operator is asked now: the account pages this shell used to carry are pages of the
    // one console, and the only thing left on it is the platform section.
    $operator = app(ConsoleScope::class)->operator();
    $identityName = $operator?->name ?? $operator?->email ?? 'Platform';
    $identityEmail = $operator?->email;

    // The heading beside the initial names what you are acting for. An operator acts for
    // the install itself, so it says so rather than borrowing a customer's word for it.
    $brandName = config('cbox-id.branding.name', 'Cbox ID');
    $brandName = is_string($brandName) && $brandName !== '' ? $brandName : 'Cbox ID';
    $contextName = $brandName;
    $contextRole = 'Platform operator';
    $accountInitial = strtoupper(substr($contextName, 0, 1));

    // The platform section's own two-tier IA, declared once in ConsoleNavigation so the
    // sidebar and the eyebrow above each page title cannot disagree.
    //
    // This shell served the retired account console as well, and took its navigation from
    // `ConsoleNavigation::workspace()` — the account areas, with the platform areas folded
    // in for whoever ran the deployment. Those account areas are areas of the ONE console
    // now (see ConsoleServiceProvider's `identity-platform`), so what is left here is the
    // platform section and nothing else. Deliberately still its own shell: folding the
    // platform pages into `layouts.app` is a reshape of the operator area, which is a
    // later step and not one that falls out of this.
    $nav = app(\App\Platform\Navigation\ConsoleNavigation::class)->operator();

    // Operator-only chrome. Operators stand above every environment, so the target
    // selector lists them all — switching just repoints reads and provisioning. Guarded
    // on the SCOPE rather than on which layout was chosen: the old operator shell was the
    // guard, and a shell is not an authorization check.
    $isOperator = $operator !== null;
    $environments = collect();
    $activeEnvId = null;
    $activeEnv = null;
    $canSwitchEnv = false;
    $lineage = [];

    if ($isOperator) {
        $ctx = app(\Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext::class);
        $activeEnvId = $ctx->current()?->environmentKey();
        // `account_id` and `project_id` come along because the switcher names the OWNER,
        // not just the plane: "Production" is a name half the customers on an install
        // will have, and a chrome control that says only that tells an operator nothing
        // about whose estate their next click lands in.
        $environments = $ctx->withoutScope(fn () => \Cbox\Id\Organization\Models\Environment::query()
            ->orderBy('created_at')->get(['id', 'name', 'slug', 'account_id', 'project_id']));
        // Two queries for the whole list, on a control that renders on EVERY console
        // page — see App\Platform\Console\EnvironmentLineages.
        $lineage = app(\App\Platform\Console\EnvironmentLineages::class)->for($environments);
        $activeEnv = $environments->firstWhere('id', $activeEnvId);
        $canSwitchEnv = $environments->count() > 1;
    }

    // The projections the shell components consume live on ConsoleNav, so all three
    // planes build their rail and sub-nav with the same code.
    $groups = $nav->groups();
    $activeGroup = ['label' => $nav->currentArea()->label];
    $railAreas = $nav->rail();
    $subnavPages = $nav->subnav();
    $isActive = fn (string $route): bool => request()->routeIs($route) || request()->routeIs($route.'.*');

    // The rail-pin cookie is written with `path=/` and no domain, so it is host-only:
    // pin the rail on cbox-id.test, open acme.cbox-id.test, and the rail is unpinned
    // again. A tenant host is a LABEL UNDER a configured base domain by construction, so
    // when one is configured the cookie can legitimately carry `.{base}` and the
    // preference follows the person across the planes they administer.
    //
    // Empty in the single-tenant shape (no base_domains), where there is one host and
    // nothing to cross. Deliberately NOT derived from the request host: a cookie domain
    // taken from `Host:` is a cookie an attacker's host can plant on ours.
    $configuredBases = config('cbox-id.environments.base_domains', []);
    $railCookieDomain = '';
    foreach (is_array($configuredBases) ? $configuredBases : [] as $base) {
        $base = is_string($base) ? ltrim(mb_strtolower(trim($base)), '.') : '';
        if ($base !== '' && (request()->getHost() === $base || str_ends_with(request()->getHost(), '.'.$base))) {
            $railCookieDomain = ';domain=.'.$base;
            break;
        }
    }
@endphp
{{-- The platform section's shell — the pages that need authority over the deployment.
     Self-contained: it assumes no tenant context, because an operator stands above every
     one of them. --}}
<!DOCTYPE html>
<html lang="en"{!! \App\Platform\Theme::attribute() !!} class="h-full {{ request()->cookie('cbox-nav-pinned') === '1' ? 'cbx-nav-pinned' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    {{-- One word, because this shell serves one section. It used to serve the account
         console too and read the plane out of the nav registry, so that a page about the
         whole install did not claim to be somebody's workspace in the tab strip. There is
         nothing left here to be mistaken for. --}}
    <title>{{ ($title ? $title.' · ' : '').'Platform · '.$brandName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @consoleBrandingStyle
</head>
<body class="h-full" style="background:var(--background);color:var(--foreground)">
{{-- The platform pages render here, and `/platform/organizations` is where impersonation
     is STARTED — so this is a layout an operator can be holding while a marker is active,
     and it had no banner and no exit control on it at all. --}}
<x-impersonation-banner />
<a href="#main-content" class="skip-link">Skip to content</a>

<div class="flex h-full" x-data="{
        pinned: {{ request()->cookie('cbox-nav-pinned') === '1' ? 'true' : 'false' }},
        subnav: localStorage.getItem('cbox-subnav-collapsed') === '1',
        nav: false, env: false, account: false, hover: false,
        togglePin() { this.pinned = !this.pinned; document.documentElement.classList.toggle('cbx-nav-pinned', this.pinned); document.cookie = 'cbox-nav-pinned=' + (this.pinned ? '1' : '0') + ';path=/{{ $railCookieDomain }};max-age=31536000;samesite=lax'; },
        toggleSubnav() { this.subnav = !this.subnav; localStorage.setItem('cbox-subnav-collapsed', this.subnav ? '1' : '0'); }
     }"
     @keydown.escape.window="nav=false;env=false;account=false"
     @keydown.window.cmd.period.prevent="toggleSubnav()" @keydown.window.ctrl.period.prevent="toggleSubnav()">

    {{-- ═══ TIER 1 — icon rail (desktop) ═══ --}}
    <x-console.rail :areas="$railAreas" :brand-href="route('platform.environments')" :brand-label="$contextName">
        <x-slot:foot>
            <x-console.account-menu :name="$identityName" :email="$identityEmail"
                                    :initial="$accountInitial" logout-route="logout" />
        </x-slot:foot>
    </x-console.rail>

    {{-- ═══ TIER 2 — contextual subnav (desktop, multi-page areas only) ═══ --}}
    @if (count($subnavPages) > 1)
        <x-console.subnav :label="$activeGroup['label']" :pages="$subnavPages" />
    @endif

    <div class="flex flex-col min-w-0 flex-1">
        {{-- Slim top bar — the account name the sidebar header used to carry. Without
             it a single-page area (Projects) would name the plane nowhere on desktop. --}}
        <header class="hidden lg:flex cbx-topbar items-center justify-between">
            <div class="flex items-center gap-2 min-w-0">
                <span class="grid place-items-center rounded-md text-[11px] font-bold shrink-0" style="width:26px;height:26px;background:var(--accent-soft);color:var(--primary)" aria-hidden="true">{{ $accountInitial }}</span>
                <span class="min-w-0">
                    <span class="block text-[13px] font-semibold truncate leading-tight">{{ $contextName }}</span>
                    <span class="block text-[11px] truncate leading-tight" style="color:var(--muted-foreground)">{{ $contextRole }}</span>
                </span>

                {{-- Target environment. Present only for whoever runs the deployment, and
                     only ever beside the account it belongs to — an operator acting on a
                     tenant should be able to see, without leaving the page, which tenant
                     that is. The old staff console said "Operator" here instead, which
                     read as a different product rather than as a wider permission. --}}
                @if ($isOperator)
                    <span style="color:var(--faint)" aria-hidden="true">/</span>
                    <div class="relative min-w-0">
                        <button type="button" class="cbx-switcher-item flex items-center gap-2 rounded-lg px-2 py-1.5 {{ $canSwitchEnv ? '' : 'pointer-events-none' }}"
                                style="transition:background-color var(--dur-hover) var(--ease)" @if ($canSwitchEnv) @click="env=!env" :aria-expanded="env" aria-haspopup="true" @endif>
                            <x-icon name="layers" class="w-4 h-4 shrink-0" style="color:var(--primary)" aria-hidden="true" />
                            <span class="min-w-0 text-left">
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
            <button type="button" data-theme-toggle class="cbx-subnav-toggle" aria-label="Toggle theme" title="Toggle theme"><x-icon name="sun" class="w-[18px] h-[18px]" /></button>
        </header>

        <main id="main-content" class="flex-1 overflow-y-auto canvas-gradient pb-16 lg:pb-0">
            {{-- Width follows the PAGE, not the plane: the platform tables need room, the
                 account forms read better narrow, and both are now in one shell. --}}
            <div class="p-6 lg:p-8 mx-auto w-full" style="max-width:{{ $width }}">{{ $slot }}</div>
        </main>
    </div>

    <x-mobile-nav :groups="$groups" :is-active="$isActive" :heading="$contextName"
                  :subheading="$contextRole" :initial="$accountInitial" logout-route="logout"
                  :member-name="$operator?->name" :member-email="$identityEmail" />
</div>
    <x-toast />
</body>
</html>

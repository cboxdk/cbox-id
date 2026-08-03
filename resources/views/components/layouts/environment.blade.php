@props(['title' => null])
@php
    use App\Platform\EnvironmentAdminAuth;
    use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
    use Cbox\Id\Organization\Models\Environment;

    // The env-admin is an ACCOUNT-layer identity administering THIS environment (the
    // control plane), never a subject in it.
    $member = app(EnvironmentAdminAuth::class)->current();
    $envKey = app(EnvironmentContext::class)->current()?->environmentKey();
    $environment = $envKey !== null ? Environment::query()->find($envKey) : null;
    $envName = $environment?->name ?? 'Environment';
    $memberInitial = strtoupper(substr($member?->name ?? $member?->email ?? 'A', 0, 1));

    // The account member's own profile/MFA/passkeys live on the ACCOUNT plane (where
    // the WebAuthn origin is valid) — link out to it from here.
    // One definition (PlaneResolver::accountHost), because the content security policy
    // names this same host — a copy that drifted would render links the policy refuses.
    // Falls back to the current host on a single-host deployment, where this plane and
    // the account plane share an origin and the link is simply local.
    $accountHost = app(\App\Platform\PlaneResolver::class)->accountHost() ?? request()->getHost();
    $securityUrl = 'https://'.$accountHost.'/workspace/security';
    $workspaceUrl = 'https://'.$accountHost.'/workspace';

    // Breadcrumb + switcher context. Where this environment sits in the account
    // hierarchy (Account › Project › Environment), and the other environments this
    // admin can jump to. Switching opens on the ACCOUNT host, which mints a fresh
    // signed handoff to the target env's own host — so no dead-end, and no second login.
    $project = null;
    $switchableEnvs = collect();
    if ($member !== null) {
        $projectId = $environment?->getAttribute('project_id');
        $project = is_string($projectId) ? \Cbox\Id\Platform\Models\Project::query()->find($projectId) : null;

        $accessibleIds = app(\Cbox\Id\Platform\Contracts\AccountMembers::class)->accessibleEnvironmentIds($member);
        $switchableEnvs = Environment::query()->whereKey($accessibleIds)->orderBy('name')->get(['id', 'name', 'slug']);
    }
    // The organization this console is acting on. The ONE thing that differs between
    // the two planes — the organization plane has exactly one and never chooses — so it
    // sits beside the environment switcher rather than inside each page.
    $consoleScope = app(\App\Platform\Console\ConsoleScope::class);
    $actingOrgId = $consoleScope->organizationId();
    $actingOrgs = $consoleScope->availableOrganizations();

    $openUrl = fn (string $id): string => 'https://'.$accountHost.route('workspace.environment.open', $id, false);

    // Two-tier IA mirroring the org console's plain-language grouping, at env scope.
    // Every resource here is env-scoped (BelongsToEnvironment); the account-member
    // admin gets full CRUD on behalf of the environment's organizations. Declared once
    // in ConsoleNavigation so the sidebar and the page eyebrow read the same list.
    $nav = app(\App\Platform\Navigation\ConsoleNavigation::class)->environment();

    // The projections the shell components consume live on ConsoleNav, so all three
    // planes build their rail and sub-nav with the same code.
    $groups = $nav->groups();
    $activeGroup = ['label' => $nav->currentArea()->label];
    $railAreas = $nav->rail();
    $subnavPages = $nav->subnav();
    $isActive = fn (string $route): bool => request()->routeIs($route) || request()->routeIs($route.'.*');
@endphp
{{-- Environment control plane — the ACCOUNT-member admin's view of ONE environment.
     Distinct from the org-user console (subjects) and the account/workspace console. --}}
<!DOCTYPE html>
<html lang="en" class="h-full {{ request()->cookie('cbox-nav-pinned') === '1' ? 'cbx-nav-pinned' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ $envName }} · Cbox ID</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full" style="background:var(--background);color:var(--foreground)">
<a href="#main-content" class="skip-link">Skip to content</a>

<div class="flex h-full" x-data="{
        pinned: {{ request()->cookie('cbox-nav-pinned') === '1' ? 'true' : 'false' }},
        subnav: localStorage.getItem('cbox-subnav-collapsed') === '1',
        nav: false, env: false, account: false, hover: false,
        togglePin() { this.pinned = !this.pinned; document.documentElement.classList.toggle('cbx-nav-pinned', this.pinned); document.cookie = 'cbox-nav-pinned=' + (this.pinned ? '1' : '0') + ';path=/;max-age=31536000;samesite=lax'; },
        toggleSubnav() { this.subnav = !this.subnav; localStorage.setItem('cbox-subnav-collapsed', this.subnav ? '1' : '0'); }
     }"
     @keydown.escape.window="nav=false;env=false;account=false"
     @keydown.window.cmd.period.prevent="toggleSubnav()" @keydown.window.ctrl.period.prevent="toggleSubnav()">

    {{-- ═══ TIER 1 — icon rail (desktop) ═══ --}}
    <x-console.rail :areas="$railAreas" :brand-href="route('environment.home')" :brand-label="$envName" >
        <x-slot:foot>
            <x-console.account-menu :name="$member?->name ?? 'Admin'" :email="$member?->email" :initial="$memberInitial" logout-route="admin.logout">
                <a href="{{ $securityUrl }}" class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;font-size:13px">
                    <x-icon name="shield-check" class="w-4 h-4" /> Profile &amp; security
                </a>
            </x-console.account-menu>
        </x-slot:foot>
    </x-console.rail>

    {{-- ═══ TIER 2 — contextual subnav (desktop, multi-page areas only) ═══ --}}
    @if (count($subnavPages) > 1)
        <x-console.subnav :label="$activeGroup['label']" :pages="$subnavPages" />
    @endif

    <div class="flex flex-col min-w-0 flex-1">
        {{-- Desktop top bar — breadcrumb home + environment switcher. Fixes the
             one-way-door: an env-admin can always get back to the account, see where
             they are (Account › Project › Environment), and jump to another env. --}}
        <header class="hidden lg:flex cbx-topbar items-center justify-between">
            <nav class="flex items-center gap-1.5 text-[13px] min-w-0" aria-label="Breadcrumb">
                <a href="{{ $workspaceUrl }}" class="shrink-0 font-medium hover:underline" style="color:var(--muted-foreground)">Account</a>
                @if ($project)
                    <span style="color:var(--faint)" aria-hidden="true">/</span>
                    <span class="shrink-0 truncate" style="color:var(--muted-foreground)">{{ $project->name }}</span>
                @endif
                <span style="color:var(--faint)" aria-hidden="true">/</span>
                <div class="relative min-w-0">
                    <button type="button" class="cbx-switcher-item flex items-center gap-1.5 rounded-lg px-1.5 py-1 {{ $switchableEnvs->count() > 1 ? '' : 'pointer-events-none' }}"
                            style="transition:background-color var(--dur-hover) var(--ease)"
                            @if ($switchableEnvs->count() > 1) @click="env=!env" :aria-expanded="env" aria-haspopup="true" @endif>
                        <span class="font-semibold truncate" aria-current="page">{{ $envName }}</span>
                        {{-- The realm indicator used to live in the sidebar header the
                             shared rail replaced; it must stay visible on desktop, or
                             staging and production read identically. --}}
                        <x-env-badge />
                        @if ($switchableEnvs->count() > 1)<x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--muted-foreground)" aria-hidden="true" />@endif
                    </button>
                    @if ($switchableEnvs->count() > 1)
                        <div x-show="env" x-transition.opacity.duration.150ms @click.outside="env=false" x-cloak
                             class="cbx-panel" style="position:absolute;top:calc(100% + 6px);left:0;min-width:240px;z-index:40;box-shadow:var(--shadow-popover);padding:6px">
                            <p class="cbx-nav-group" style="padding:6px 10px 4px">Switch environment</p>
                            @foreach ($switchableEnvs as $e)
                                <a href="{{ $openUrl($e->id) }}" class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;{{ $e->id === $envKey ? 'background:var(--secondary)' : '' }}">
                                    <x-icon name="layers" class="w-3.5 h-3.5 shrink-0" style="color:var(--muted-foreground)" aria-hidden="true" />
                                    <span class="min-w-0 flex-1"><span class="block truncate">{{ $e->name }}</span><span class="block text-[11px] mono truncate" style="color:var(--muted-foreground)">{{ $e->slug }}</span></span>
                                    @if ($e->id === $envKey)<x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" aria-hidden="true" />@endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Acting organization. Every page below is scoped to it, so it belongs
                     in the breadcrumb next to the environment rather than repeated as a
                     field on each page — which is how the two consoles came to disagree
                     about which organization a form was writing to. --}}
                @if ($actingOrgs !== [])
                    <span style="color:var(--faint)" aria-hidden="true">/</span>
                    <div class="relative min-w-0" x-data="{ org: false }">
                        <button type="button" class="cbx-switcher-item flex items-center gap-1.5 rounded-lg px-1.5 py-1"
                                style="transition:background-color var(--dur-hover) var(--ease)"
                                @click="org=!org" :aria-expanded="org" aria-haspopup="true">
                            <span class="truncate {{ $actingOrgId === null ? 'italic' : 'font-semibold' }}"
                                  style="{{ $actingOrgId === null ? 'color:var(--muted-foreground)' : '' }}">
                                {{ $actingOrgId === null ? 'Choose organization' : ($actingOrgs[$actingOrgId] ?? 'Unknown') }}
                            </span>
                            <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--muted-foreground)" aria-hidden="true" />
                        </button>
                        <div x-show="org" x-transition.opacity.duration.150ms @click.outside="org=false" x-cloak
                             class="cbx-panel" style="position:absolute;top:calc(100% + 6px);left:0;min-width:240px;z-index:40;box-shadow:var(--shadow-popover);padding:6px">
                            <p class="cbx-nav-group" style="padding:6px 10px 4px">Act on behalf of</p>
                            @foreach ($actingOrgs as $orgId => $orgName)
                                <form method="POST" action="{{ route('environment.organization.choose', [], false) }}">@csrf
                                    <input type="hidden" name="organization" value="{{ $orgId }}">
                                    <button type="submit" class="cbx-row w-full text-start" style="padding:8px 10px;border-radius:6px;gap:10px;{{ $orgId === $actingOrgId ? 'background:var(--secondary)' : '' }}">
                                        <span class="min-w-0 flex-1 truncate">{{ $orgName }}</span>
                                        @if ($orgId === $actingOrgId)<x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" aria-hidden="true" />@endif
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif
            </nav>
            <button type="button" data-theme-toggle class="cbx-subnav-toggle" aria-label="Toggle theme" title="Toggle theme"><x-icon name="sun" class="w-[18px] h-[18px]" /></button>
        </header>

        <main id="main-content" class="flex-1 min-w-0 overflow-y-auto canvas-gradient pb-16 lg:pb-0">
            <div class="mx-auto w-full max-w-5xl px-5 py-8">
                {{ $slot }}
            </div>
        </main>
    </div>

    <x-mobile-nav :groups="$groups" :is-active="$isActive" :heading="$envName" subheading="Environment admin"
                  :initial="$memberInitial" logout-route="admin.logout"
                  :member-name="$member?->name" :member-email="$member?->email" :security-url="$securityUrl">
        <a href="{{ $workspaceUrl }}" class="nav-link w-full"><x-icon name="chevron" class="w-4 h-4" style="transform:rotate(90deg)" aria-hidden="true" /> Back to account</a>
        @if ($switchableEnvs->count() > 1)
            <p class="cbx-nav-group px-2 pt-2 pb-1">Switch environment</p>
            <div class="max-h-52 overflow-y-auto space-y-0.5">
                @foreach ($switchableEnvs as $e)
                    <a href="{{ $openUrl($e->id) }}" class="cbx-row w-full" style="padding:8px 10px;border-radius:8px;gap:10px;{{ $e->id === $envKey ? 'background:var(--secondary)' : '' }}">
                        <x-icon name="layers" class="w-3.5 h-3.5 shrink-0" style="color:var(--muted-foreground)" aria-hidden="true" />
                        <span class="min-w-0 flex-1"><span class="block text-[13px] truncate">{{ $e->name }}</span><span class="block text-[11px] mono truncate" style="color:var(--muted-foreground)">{{ $e->slug }}</span></span>
                        @if ($e->id === $envKey)<x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" aria-hidden="true" />@endif
                    </a>
                @endforeach
            </div>
        @endif
    </x-mobile-nav>
</div>
@livewireScripts
    <x-toast />
</body>
</html>

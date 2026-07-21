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
    $bases = config('cbox-id.environments.base_domains', []);
    $accountHost = is_array($bases) && isset($bases[0]) && is_string($bases[0]) ? $bases[0] : request()->getHost();
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
    $openUrl = fn (string $id): string => 'https://'.$accountHost.route('workspace.environment.open', $id, false);

    // Two-tier IA mirroring the org console's plain-language grouping, at env scope.
    // Every resource here is env-scoped (BelongsToEnvironment); the account-member
    // admin gets full CRUD on behalf of the environment's organizations.
    $groups = [
        ['label' => 'Overview', 'icon' => 'dashboard', 'pages' => [
            ['route' => 'environment.home', 'label' => 'Overview'],
            ['route' => 'environment.analytics', 'label' => 'Analytics'],
            ['route' => 'environment.approvals', 'label' => 'Agent approvals'],
        ]],
        ['label' => 'Tenants', 'icon' => 'layers', 'pages' => [
            ['route' => 'environment.organizations', 'label' => 'Organizations'],
        ]],
        ['label' => 'People', 'icon' => 'members', 'pages' => [
            ['route' => 'environment.users', 'label' => 'Users'],
            ['route' => 'environment.roles', 'label' => 'Roles'],
            ['route' => 'environment.permissions', 'label' => 'Permissions'],
        ]],
        ['label' => 'Sign-in', 'icon' => 'connections', 'pages' => [
            ['route' => 'environment.connections', 'label' => 'Single sign-on'],
            ['route' => 'environment.sso-providers', 'label' => 'Login methods'],
            ['route' => 'environment.directories', 'label' => 'Directories'],
            ['route' => 'environment.provisioning', 'label' => 'Outbound sync'],
        ]],
        ['label' => 'Access control', 'icon' => 'shield-check', 'pages' => [
            ['route' => 'environment.governance', 'label' => 'Access reviews'],
            ['route' => 'environment.sod-policies', 'label' => 'Conflict rules'],
        ]],
        ['label' => 'Developers', 'icon' => 'clients', 'pages' => [
            ['route' => 'environment.clients', 'label' => 'Applications'],
            ['route' => 'environment.webhooks', 'label' => 'Webhooks'],
            ['route' => 'environment.hooks', 'label' => 'Event hooks'],
            ['route' => 'environment.vault', 'label' => 'Stored tokens'],
        ]],
        ['label' => 'Logs', 'icon' => 'audit', 'pages' => [
            ['route' => 'environment.audit', 'label' => 'Audit log'],
            ['route' => 'environment.audit-streams', 'label' => 'Log streaming'],
        ]],
        ['label' => 'Settings', 'icon' => 'settings', 'pages' => [
            ['route' => 'environment.settings', 'label' => 'Settings'],
            ['route' => 'environment.appearance', 'label' => 'Appearance'],
        ]],
    ];

    // A nav item stays active on its own detail/create routes (e.g. environment.users
    // → environment.users.show) but NOT on a sibling that merely shares a prefix
    // (environment.audit must not light up on environment.audit-streams).
    $isActive = fn (string $route): bool => request()->routeIs($route) || request()->routeIs($route.'.*');
@endphp
{{-- Environment control plane — the ACCOUNT-member admin's view of ONE environment.
     Distinct from the org-user console (subjects) and the account/workspace console. --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ $envName }} · Cbox ID</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full" style="background:var(--background);color:var(--foreground)">
<a href="#main-content" class="skip-link">Skip to content</a>

<div class="flex h-full" x-data="{ nav: false, env: false }" @keydown.escape.window="nav=false;env=false">
    <aside class="hidden lg:flex flex-col shrink-0 w-60" style="background:var(--sidebar);border-right:1px solid var(--sidebar-border)">
        <div class="flex items-center gap-2 px-4 h-14 shrink-0" style="border-bottom:1px solid var(--sidebar-border)">
            <div class="min-w-0">
                <p class="text-sm font-semibold truncate">{{ $envName }}</p>
                <p class="text-xs truncate" style="color:var(--faint)">Environment admin</p>
            </div>
        </div>
        <nav class="flex-1 overflow-y-auto p-3 space-y-3" aria-label="Environment areas">
            @foreach ($groups as $group)
                <div class="space-y-0.5">
                    <p class="cbx-nav-group flex items-center gap-2 px-2 pb-1 text-[11px] font-semibold uppercase tracking-wide" style="color:var(--faint)">
                        <x-icon :name="$group['icon']" class="w-3.5 h-3.5" /> {{ $group['label'] }}
                    </p>
                    @foreach ($group['pages'] as $page)
                        <a href="{{ route($page['route']) }}" class="nav-link {{ $isActive($page['route']) ? 'is-active' : '' }}"
                           @if ($isActive($page['route'])) aria-current="page" @endif>
                            {{ $page['label'] }}
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>
        <div class="p-3 space-y-0.5" style="border-top:1px solid var(--sidebar-border)">
            <div class="flex items-center gap-2 px-2 py-1">
                <span class="grid place-items-center w-7 h-7 rounded-full text-xs font-semibold shrink-0" style="background:var(--accent-soft);color:var(--accent)">{{ $memberInitial }}</span>
                <div class="min-w-0">
                    <p class="text-sm truncate">{{ $member?->name ?? 'Admin' }}</p>
                    <p class="text-xs truncate" style="color:var(--faint)">{{ $member?->email }}</p>
                </div>
            </div>
            <a href="{{ $securityUrl }}" class="nav-link w-full"><x-icon name="shield-check" class="w-[1.15rem] h-[1.15rem]" /> Profile &amp; security</a>
            <button type="button" data-theme-toggle class="nav-link w-full"><x-icon name="moon" class="w-[1.15rem] h-[1.15rem]" /> Toggle theme</button>
            <form method="POST" action="{{ route('admin.logout') }}">@csrf
                <button type="submit" class="nav-link w-full" style="color:var(--destructive)"><x-icon name="logout" class="w-[1.15rem] h-[1.15rem]" /> Sign out</button>
            </form>
        </div>
    </aside>

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
                    <button type="button" class="flex items-center gap-1.5 rounded-lg px-1.5 py-1 {{ $switchableEnvs->count() > 1 ? '' : 'pointer-events-none' }}"
                            style="transition:background-color var(--dur-hover) var(--ease)"
                            @if ($switchableEnvs->count() > 1) @click="env=!env" onmouseover="this.style.background='var(--secondary)'" onmouseout="this.style.background='transparent'" :aria-expanded="env" aria-haspopup="true" @endif>
                        <span class="font-semibold truncate" aria-current="page">{{ $envName }}</span>
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

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

    // Two-tier IA mirroring the org console's plain-language grouping, at env scope.
    // Every resource here is env-scoped (BelongsToEnvironment); the account-member
    // admin gets full CRUD on behalf of the environment's organizations.
    $groups = [
        ['label' => 'Overview', 'icon' => 'dashboard', 'pages' => [
            ['route' => 'environment.home', 'label' => 'Overview'],
            ['route' => 'environment.analytics', 'label' => 'Usage'],
            ['route' => 'environment.approvals', 'label' => 'Agent approvals'],
        ]],
        ['label' => 'Tenants', 'icon' => 'layers', 'pages' => [
            ['route' => 'environment.organizations', 'label' => 'Organizations'],
        ]],
        ['label' => 'People', 'icon' => 'members', 'pages' => [
            ['route' => 'environment.users', 'label' => 'Users'],
            ['route' => 'environment.roles', 'label' => 'Roles'],
        ]],
        ['label' => 'Sign-in', 'icon' => 'connections', 'pages' => [
            ['route' => 'environment.connections', 'label' => 'Single sign-on'],
            ['route' => 'environment.sso-providers', 'label' => 'Login methods'],
            ['route' => 'environment.directories', 'label' => 'User sync'],
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
            ['route' => 'environment.audit', 'label' => 'Activity log'],
            ['route' => 'environment.audit-streams', 'label' => 'Log streaming'],
        ]],
        ['label' => 'Settings', 'icon' => 'settings', 'pages' => [
            ['route' => 'environment.settings', 'label' => 'Settings'],
        ]],
    ];
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

<div class="flex h-full" x-data="{ nav: false }" @keydown.escape.window="nav=false">
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
                        <a href="{{ route($page['route']) }}" class="nav-link {{ request()->routeIs($page['route']) ? 'is-active' : '' }}">
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
        {{-- Mobile top bar --}}
        <header class="lg:hidden flex items-center justify-between px-4 h-14 shrink-0" style="border-bottom:1px solid var(--sidebar-border)">
            <span class="min-w-0">
                <span class="block text-[13px] font-semibold truncate leading-tight">{{ $envName }}</span>
                <span class="block text-[11px] leading-tight" style="color:var(--faint)">Environment admin</span>
            </span>
            <button type="button" @click="nav=!nav" class="cbx-subnav-toggle" aria-label="Menu"><x-icon name="menu" class="w-[18px] h-[18px]" /></button>
        </header>

        {{-- Mobile nav drawer --}}
        <div x-show="nav" x-cloak class="lg:hidden px-3 py-2 space-y-3" style="border-bottom:1px solid var(--sidebar-border)">
            @foreach ($groups as $group)
                <div class="space-y-0.5">
                    <p class="cbx-nav-group flex items-center gap-2 px-2 pb-1 text-[11px] font-semibold uppercase tracking-wide" style="color:var(--faint)">
                        <x-icon :name="$group['icon']" class="w-3.5 h-3.5" aria-hidden="true" /> {{ $group['label'] }}
                    </p>
                    @foreach ($group['pages'] as $page)
                        <a href="{{ route($page['route']) }}" class="nav-link {{ request()->routeIs($page['route']) ? 'is-active' : '' }}">
                            {{ $page['label'] }}
                        </a>
                    @endforeach
                </div>
            @endforeach
            <div class="space-y-0.5 pt-2" style="border-top:1px solid var(--sidebar-border)">
            <a href="{{ $securityUrl }}" class="nav-link w-full"><x-icon name="shield-check" class="w-[1.15rem] h-[1.15rem]" /> Profile &amp; security</a>
            <button type="button" data-theme-toggle class="nav-link w-full"><x-icon name="moon" class="w-[1.15rem] h-[1.15rem]" /> Toggle theme</button>
            <form method="POST" action="{{ route('admin.logout') }}">@csrf
                <button type="submit" class="nav-link w-full" style="color:var(--destructive)"><x-icon name="logout" class="w-[1.15rem] h-[1.15rem]" /> Sign out</button>
            </form>
            </div>
        </div>

        <main id="main-content" class="flex-1 min-w-0 overflow-y-auto">
            <div class="mx-auto w-full max-w-5xl px-5 py-8">
                @if (session('status'))
                    <div class="mb-6 rounded-xl border p-3 text-sm" style="border-color:color-mix(in oklch,var(--success) 35%,transparent);background:var(--success-soft);color:var(--success)">{{ session('status') }}</div>
                @endif
                {{ $slot }}
            </div>
        </main>
    </div>
</div>
@livewireScripts
</body>
</html>

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

    $areas = [
        ['route' => 'environment.home', 'label' => 'Overview', 'icon' => 'dashboard'],
        ['route' => 'environment.organizations', 'label' => 'Organizations', 'icon' => 'layers'],
        ['route' => 'environment.users', 'label' => 'Users', 'icon' => 'members'],
        ['route' => 'environment.clients', 'label' => 'Applications', 'icon' => 'clients'],
        ['route' => 'environment.connections', 'label' => 'Single sign-on', 'icon' => 'connections'],
        ['route' => 'environment.directories', 'label' => 'Directories', 'icon' => 'directory'],
        ['route' => 'environment.roles', 'label' => 'Roles', 'icon' => 'roles'],
        ['route' => 'environment.webhooks', 'label' => 'Webhooks', 'icon' => 'webhooks'],
        ['route' => 'environment.audit', 'label' => 'Audit log', 'icon' => 'audit'],
        ['route' => 'environment.analytics', 'label' => 'Analytics', 'icon' => 'chart'],
        ['route' => 'environment.settings', 'label' => 'Settings', 'icon' => 'settings'],
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
        <nav class="flex-1 overflow-y-auto p-3 space-y-0.5" aria-label="Environment areas">
            @foreach ($areas as $area)
                <a href="{{ route($area['route']) }}" class="nav-link {{ request()->routeIs($area['route']) ? 'is-active' : '' }}">
                    <x-icon :name="$area['icon']" class="w-[1.15rem] h-[1.15rem]" /> {{ $area['label'] }}
                </a>
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
        <div x-show="nav" x-cloak class="lg:hidden px-3 py-2 space-y-0.5" style="border-bottom:1px solid var(--sidebar-border)">
            @foreach ($areas as $area)
                <a href="{{ route($area['route']) }}" class="nav-link {{ request()->routeIs($area['route']) ? 'is-active' : '' }}">
                    <x-icon :name="$area['icon']" class="w-[1.15rem] h-[1.15rem]" aria-hidden="true" /> {{ $area['label'] }}
                </a>
            @endforeach
            <button type="button" data-theme-toggle class="nav-link w-full"><x-icon name="moon" class="w-[1.15rem] h-[1.15rem]" /> Toggle theme</button>
            <form method="POST" action="{{ route('admin.logout') }}">@csrf
                <button type="submit" class="nav-link w-full" style="color:var(--destructive)"><x-icon name="logout" class="w-[1.15rem] h-[1.15rem]" /> Sign out</button>
            </form>
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

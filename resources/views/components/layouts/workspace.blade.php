@props(['title' => null])
@php
    use App\Platform\AccountAuth;

    $member = app(AccountAuth::class)->current();
    $account = $member?->account;
    $accountInitial = strtoupper(substr($account?->name ?? 'W', 0, 1));

    // Nav is role-aware: API keys (high-privilege) only for member managers, Billing
    // only for roles that can manage it.
    $areas = array_values(array_filter([
        ['route' => 'workspace.home', 'label' => 'Environments', 'icon' => 'layers'],
        $member?->role->canReadMembers()
            ? ['route' => 'workspace.members', 'label' => 'Members', 'icon' => 'members']
            : null,
        ['route' => 'workspace.security', 'label' => 'Security', 'icon' => 'shield'],
        $member?->role->canManageMembers()
            ? ['route' => 'workspace.api-keys', 'label' => 'API keys', 'icon' => 'key']
            : null,
        $member?->role->canManageEnvironments()
            ? ['route' => 'workspace.environment-keys', 'label' => 'Environment keys', 'icon' => 'key']
            : null,
        $member?->role->canReadBilling()
            ? ['route' => 'workspace.billing', 'label' => 'Billing', 'icon' => 'dashboard']
            : null,
        $member?->role->canManageMembers()
            ? ['route' => 'workspace.settings', 'label' => 'Settings', 'icon' => 'settings']
            : null,
    ]));
@endphp
{{-- The workspace console shell — the account-member (buyer) plane. Self-contained:
     it assumes NO org-user or operator context (an account member has neither). --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <title>{{ ($title ? $title.' · ' : '').'Workspace · '.config('cbox-id.branding.name', 'Cbox ID') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @consoleBrandingStyle
</head>
<body class="h-full" style="background:var(--background);color:var(--foreground)">
<a href="#main-content" class="skip-link">Skip to content</a>

<div class="flex h-full" x-data="{ nav: false }" @keydown.escape.window="nav=false">
    {{-- ═══ Sidebar — the account layer ═══ --}}
    <aside class="hidden lg:flex flex-col shrink-0 w-60" style="background:var(--sidebar);border-right:1px solid var(--sidebar-border)">
        <div class="flex items-center gap-2.5 px-4 h-14 shrink-0" style="border-bottom:1px solid var(--sidebar-border)">
            <span class="grid place-items-center w-8 h-8 rounded-lg text-sm font-semibold shrink-0" style="background:var(--accent);color:var(--accent-fg)" aria-hidden="true">{{ $accountInitial }}</span>
            <span class="min-w-0">
                <span class="block text-[13px] font-semibold truncate leading-tight">{{ $account?->name ?? 'Workspace' }}</span>
                <span class="block text-[11px] leading-tight" style="color:var(--muted-foreground)">Account</span>
            </span>
        </div>

        <nav class="flex-1 overflow-y-auto p-3 space-y-0.5" aria-label="Account areas">
            @foreach ($areas as $area)
                <a href="{{ route($area['route']) }}" class="nav-link"
                   @if (request()->routeIs($area['route'])) aria-current="page" @endif>
                    <x-icon :name="$area['icon']" class="w-[1.15rem] h-[1.15rem] shrink-0" aria-hidden="true" />
                    {{ $area['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="p-3" style="border-top:1px solid var(--sidebar-border)">
            <div class="px-1 mb-2 min-w-0">
                <p class="text-[13px] font-medium truncate">{{ $member?->name ?? $member?->email }}</p>
                <p class="text-[11px] truncate" style="color:var(--muted-foreground)">{{ $member?->email }}</p>
            </div>
            <button type="button" data-theme-toggle class="nav-link w-full"><x-icon name="moon" class="w-[1.15rem] h-[1.15rem]" /> Toggle theme</button>
            <form method="POST" action="{{ route('workspace.logout') }}">@csrf
                <button type="submit" class="nav-link w-full" style="color:var(--destructive)"><x-icon name="logout" class="w-[1.15rem] h-[1.15rem]" /> Sign out</button>
            </form>
        </div>
    </aside>

    {{-- ═══ Mobile top bar ═══ --}}
    <div class="flex flex-col min-w-0 flex-1">
        <header class="lg:hidden flex items-center justify-between px-4 h-14 shrink-0" style="border-bottom:1px solid var(--border)">
            <span class="flex items-center gap-2 min-w-0">
                <span class="grid place-items-center w-7 h-7 rounded-lg text-xs font-semibold shrink-0" style="background:var(--accent);color:var(--accent-fg)">{{ $accountInitial }}</span>
                <span class="text-[13px] font-semibold truncate">{{ $account?->name ?? 'Workspace' }}</span>
            </span>
            <button type="button" @click="nav=!nav" class="cbx-subnav-toggle" aria-label="Menu"><x-icon name="menu" class="w-[18px] h-[18px]" /></button>
        </header>

        {{-- Mobile nav drawer --}}
        <div x-show="nav" x-cloak class="lg:hidden px-3 py-2 space-y-0.5" style="border-bottom:1px solid var(--border)">
            @foreach ($areas as $area)
                <a href="{{ route($area['route']) }}" class="nav-link">
                    <x-icon :name="$area['icon']" class="w-[1.15rem] h-[1.15rem]" aria-hidden="true" /> {{ $area['label'] }}
                </a>
            @endforeach
            <form method="POST" action="{{ route('workspace.logout') }}">@csrf
                <button type="submit" class="nav-link w-full" style="color:var(--destructive)"><x-icon name="logout" class="w-[1.15rem] h-[1.15rem]" /> Sign out</button>
            </form>
        </div>

        <main id="main-content" class="flex-1 overflow-y-auto canvas-gradient">
            @if (session('status'))
                <div role="status" aria-live="polite" class="mx-6 mt-6 -mb-2 rounded-lg px-4 py-3 text-sm"
                     style="background:var(--success-soft);color:var(--success);border:1px solid color-mix(in oklch,var(--success) 20%,transparent)">{{ session('status') }}</div>
            @endif
            <div class="p-6 lg:p-8 mx-auto w-full" style="max-width:48rem">{{ $slot }}</div>
        </main>
    </div>
</div>
</body>
</html>

@props(['title' => null])
@php
    use App\Platform\AccountAuth;

    $member = app(AccountAuth::class)->current();
    $account = $member?->account;
    $accountInitial = strtoupper(substr($account?->name ?? 'W', 0, 1));

    // Two-tier IA (grouped), role-aware. Same shape as the environment console so the
    // whole product navigates consistently. Empty groups (a role sees none of a
    // group's pages) are dropped.
    $groups = array_values(array_filter(array_map(fn (array $g): array => [
        'label' => $g['label'], 'icon' => $g['icon'],
        'pages' => array_values(array_filter($g['pages'])),
    ], [
        ['label' => 'Overview', 'icon' => 'dashboard', 'pages' => [
            ['route' => 'workspace.home', 'label' => 'Projects'],
        ]],
        ['label' => 'People', 'icon' => 'members', 'pages' => [
            $member?->role->canReadMembers() ? ['route' => 'workspace.members', 'label' => 'Members'] : null,
        ]],
        ['label' => 'Developers', 'icon' => 'clients', 'pages' => [
            $member?->role->canManageMembers() ? ['route' => 'workspace.api-keys', 'label' => 'API keys'] : null,
            $member?->role->canManageEnvironments() ? ['route' => 'workspace.environment-keys', 'label' => 'Environment keys'] : null,
            $member?->role->canManageEnvironments() ? ['route' => 'workspace.environment-domains', 'label' => 'Domains'] : null,
        ]],
        ['label' => 'Account', 'icon' => 'settings', 'pages' => [
            $member?->role->canReadMembers() ? ['route' => 'workspace.activity', 'label' => 'Activity'] : null,
            $member?->role->canReadBilling() ? ['route' => 'workspace.billing', 'label' => 'Billing'] : null,
            $member?->role->canManageMembers() ? ['route' => 'workspace.settings', 'label' => 'Settings'] : null,
        ]],
        // The member's OWN identity/2FA/passkeys — a personal concern, not an
        // account-level setting, so it gets its own section.
        ['label' => 'Personal', 'icon' => 'shield-check', 'pages' => [
            ['route' => 'workspace.security', 'label' => 'Profile'],
        ]],
    ]), fn (array $g): bool => $g['pages'] !== []));

    $isActive = fn (string $route): bool => request()->routeIs($route) || request()->routeIs($route.'.*');

    // ── Shared two-tier shell (design system: guidelines/app-shell). TIER 1 = one
    // rail icon per group; TIER 2 = the active group's pages, only when it has more
    // than one. Several groups here are single-page (Projects, Members, Profile) —
    // those deliberately render NO tier 2 at all.
    $activeGroup = collect($groups)->first(
        fn (array $g): bool => collect($g['pages'])->contains(fn (array $p): bool => $isActive($p['route']))
    ) ?? $groups[0];

    $railAreas = collect($groups)->map(fn (array $g): array => [
        'key' => $g['label'],
        'label' => $g['label'],
        'icon' => $g['icon'],
        'href' => route($g['pages'][0]['route']),
        'active' => $g['label'] === $activeGroup['label'],
        'current' => $g['label'] === $activeGroup['label'] && count($g['pages']) === 1,
    ])->all();
    $subnavPages = collect($activeGroup['pages'])->map(fn (array $p): array => [
        'href' => route($p['route']),
        'label' => $p['label'],
        'active' => $isActive($p['route']),
    ])->all();
@endphp
{{-- The workspace console shell — the account-member (buyer) plane. Self-contained:
     it assumes NO org-user or operator context (an account member has neither). --}}
<!DOCTYPE html>
<html lang="en" class="h-full {{ request()->cookie('cbox-nav-pinned') === '1' ? 'cbx-nav-pinned' : '' }}">
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

<div class="flex h-full" x-data="{
        pinned: {{ request()->cookie('cbox-nav-pinned') === '1' ? 'true' : 'false' }},
        subnav: localStorage.getItem('cbox-subnav-collapsed') === '1',
        nav: false, account: false, hover: false,
        togglePin() { this.pinned = !this.pinned; document.documentElement.classList.toggle('cbx-nav-pinned', this.pinned); document.cookie = 'cbox-nav-pinned=' + (this.pinned ? '1' : '0') + ';path=/;max-age=31536000;samesite=lax'; },
        toggleSubnav() { this.subnav = !this.subnav; localStorage.setItem('cbox-subnav-collapsed', this.subnav ? '1' : '0'); }
     }"
     @keydown.escape.window="nav=false;account=false"
     @keydown.window.cmd.period.prevent="toggleSubnav()" @keydown.window.ctrl.period.prevent="toggleSubnav()">

    {{-- ═══ TIER 1 — icon rail (desktop) ═══ --}}
    <x-console.rail :areas="$railAreas" :brand-href="route('workspace.home')" :brand-label="$account?->name ?? 'Workspace'">
        <x-slot:foot>
            <x-console.account-menu :name="$member?->name ?? $member?->email ?? 'Account'" :email="$member?->email"
                                    :initial="$accountInitial" logout-route="workspace.logout" />
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
                    <span class="block text-[13px] font-semibold truncate leading-tight">{{ $account?->name ?? 'Workspace' }}</span>
                    <span class="block text-[11px] truncate leading-tight" style="color:var(--muted-foreground)">Account</span>
                </span>
            </div>
            <button type="button" data-theme-toggle class="cbx-subnav-toggle" aria-label="Toggle theme" title="Toggle theme"><x-icon name="sun" class="w-[18px] h-[18px]" /></button>
        </header>

        <main id="main-content" class="flex-1 overflow-y-auto canvas-gradient pb-16 lg:pb-0">
            <div class="p-6 lg:p-8 mx-auto w-full" style="max-width:48rem">{{ $slot }}</div>
        </main>
    </div>

    <x-mobile-nav :groups="$groups" :is-active="$isActive" :heading="$account?->name ?? 'Workspace'"
                  subheading="Account" :initial="$accountInitial" logout-route="workspace.logout"
                  :member-name="$member?->name" :member-email="$member?->email" />
</div>
    <x-toast />
</body>
</html>

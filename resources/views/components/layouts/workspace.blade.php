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
    {{-- ═══ Desktop sidebar — 2-tier grouped ═══ --}}
    <aside class="hidden lg:flex flex-col shrink-0 w-60" style="background:var(--sidebar);border-right:1px solid var(--sidebar-border)">
        <div class="flex items-center gap-2.5 px-4 h-14 shrink-0" style="border-bottom:1px solid var(--sidebar-border)">
            <span class="grid place-items-center w-8 h-8 rounded-lg text-sm font-semibold shrink-0" style="background:var(--accent);color:var(--accent-fg)" aria-hidden="true">{{ $accountInitial }}</span>
            <span class="min-w-0">
                <span class="block text-[13px] font-semibold truncate leading-tight">{{ $account?->name ?? 'Workspace' }}</span>
                <span class="block text-[11px] leading-tight" style="color:var(--muted-foreground)">Account</span>
            </span>
        </div>

        <nav class="flex-1 overflow-y-auto p-3 space-y-3" aria-label="Account areas">
            @foreach ($groups as $group)
                <div class="space-y-0.5">
                    <p class="cbx-nav-group flex items-center gap-2 px-2 pb-1 text-[11px] font-semibold uppercase tracking-wide" style="color:var(--faint)">
                        <x-icon :name="$group['icon']" class="w-3.5 h-3.5" /> {{ $group['label'] }}
                    </p>
                    @foreach ($group['pages'] as $page)
                        <a href="{{ route($page['route']) }}" class="nav-link {{ $isActive($page['route']) ? 'is-active' : '' }}"
                           @if ($isActive($page['route'])) aria-current="page" @endif>{{ $page['label'] }}</a>
                    @endforeach
                </div>
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

    <div class="flex flex-col min-w-0 flex-1">
        <main id="main-content" class="flex-1 overflow-y-auto canvas-gradient pb-16 lg:pb-0">
            @if (session('status'))
                <div role="status" aria-live="polite" class="mx-6 mt-6 -mb-2 rounded-lg px-4 py-3 text-sm"
                     style="background:var(--success-soft);color:var(--success);border:1px solid color-mix(in oklch,var(--success) 20%,transparent)">{{ session('status') }}</div>
            @endif
            <div class="p-6 lg:p-8 mx-auto w-full" style="max-width:48rem">{{ $slot }}</div>
        </main>
    </div>

    <x-mobile-nav :groups="$groups" :is-active="$isActive" :heading="$account?->name ?? 'Workspace'"
                  subheading="Account" :initial="$accountInitial" logout-route="workspace.logout"
                  :member-name="$member?->name" :member-email="$member?->email" />
</div>
</body>
</html>

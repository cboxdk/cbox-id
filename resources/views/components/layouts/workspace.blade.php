@props(['title' => null])
@php
    use App\Platform\AccountAuth;

    $member = app(AccountAuth::class)->current();
    $account = $member?->account;
    $accountInitial = strtoupper(substr($account?->name ?? 'W', 0, 1));

    // Two-tier IA (grouped), role-aware — declared once in ConsoleNavigation so the
    // sidebar and the eyebrow above each page title cannot disagree.
    $nav = app(\App\Platform\Navigation\ConsoleNavigation::class)->workspace($member?->role);

    // The projections the shell components consume live on ConsoleNav, so all three
    // planes build their rail and sub-nav with the same code.
    $groups = $nav->groups();
    $activeGroup = ['label' => $nav->currentArea()->label];
    $railAreas = $nav->rail();
    $subnavPages = $nav->subnav();
    $isActive = fn (string $route): bool => request()->routeIs($route) || request()->routeIs($route.'.*');
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

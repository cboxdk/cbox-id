@props(['title' => null])
@php
    use App\Platform\OperatorAuth;

    $operator = app(OperatorAuth::class)->current();

    // Operators stand above every environment, so the target-plane selector lists
    // them all with no identity guard — switching just repoints reads/provisioning.
    $ctx = app(\Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext::class);
    $activeEnvId = $ctx->current()?->environmentKey();
    $environments = $ctx->withoutScope(fn () => \Cbox\Id\Organization\Models\Environment::query()
        ->orderBy('created_at')->get(['id', 'name', 'slug']));
    $activeEnv = $environments->firstWhere('id', $activeEnvId);
    $canSwitchEnv = $environments->count() > 1;

    // Two-tier IA, consistent with the workspace and environment consoles.
    $groups = [
        ['label' => 'Platform', 'icon' => 'layers', 'pages' => [
            ['route' => 'operator.environments', 'label' => 'Environments'],
            ['route' => 'operator.organizations', 'label' => 'Organizations'],
        ]],
        ['label' => 'Insights', 'icon' => 'dashboard', 'pages' => [
            ['route' => 'operator.usage', 'label' => 'Usage'],
            ['route' => 'operator.search', 'label' => 'Search'],
        ]],
        ['label' => 'Administration', 'icon' => 'shield', 'pages' => [
            ['route' => 'operator.operators', 'label' => 'Operators'],
            ['route' => 'operator.security', 'label' => 'Security'],
        ]],
    ];
    $isActive = fn (string $route): bool => request()->routeIs($route) || request()->routeIs($route.'.*');
    $operatorInitial = strtoupper(substr($operator?->name ?? $operator?->email ?? 'O', 0, 1));

    // ── Shared two-tier shell (design system: guidelines/app-shell). TIER 1 = one
    // rail icon per group; TIER 2 = the active group's pages, only when it has >1.
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
<!DOCTYPE html>
<html lang="en" class="h-full {{ request()->cookie('cbox-nav-pinned') === '1' ? 'cbx-nav-pinned' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <title>{{ ($title ? $title.' · ' : '').'Operator · '.config('cbox-id.branding.name', 'Cbox ID') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Per-tenant console branding when the whitelabel plugin is installed; inert otherwise. --}}
    @consoleBrandingStyle
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
    <x-console.rail :areas="$railAreas" :brand-href="route('operator.environments')" brand-label="Operator console">
        <x-slot:foot>
            <x-console.account-menu :name="$operator?->name ?? $operator?->email ?? 'Operator'"
                                    email="Platform operator" :initial="$operatorInitial" logout-route="operator.logout" />
        </x-slot:foot>
    </x-console.rail>

    {{-- ═══ TIER 2 — contextual subnav (desktop, multi-page areas only) ═══ --}}
    @if (count($subnavPages) > 1)
        <x-console.subnav :label="$activeGroup['label']" :pages="$subnavPages" />
    @endif

    {{-- ═══ Main column ═══ --}}
    <div class="flex flex-col min-w-0 flex-1">
        {{-- Slim top bar — names the plane (this is the god-mode console; it must never
             read as an ordinary one) and carries the target-environment context. --}}
        <header class="hidden lg:flex cbx-topbar items-center justify-between">
            <div class="flex items-center gap-2 min-w-0">
                <span class="shrink-0 text-[13px] font-semibold" style="font-family:var(--font-display)">Operator</span>
                <span style="color:var(--faint)" aria-hidden="true">/</span>
                <div class="relative min-w-0">
                <button type="button" class="cbx-switcher-item flex items-center gap-2 rounded-lg px-2 py-1.5 {{ $canSwitchEnv ? '' : 'pointer-events-none' }}"
                        style="transition:background-color var(--dur-hover) var(--ease)" @if ($canSwitchEnv) @click="env=!env" :aria-expanded="env" aria-haspopup="true" @endif>
                    <x-icon name="layers" class="w-4 h-4 shrink-0" style="color:var(--primary)" aria-hidden="true" />
                    <span class="min-w-0 text-left">
                        <span class="block text-[10px] font-medium uppercase tracking-wide leading-tight" style="color:var(--muted-foreground)">Target environment</span>
                        <span class="block text-[13px] font-semibold truncate leading-tight">{{ $activeEnv?->name ?? 'None yet' }}</span>
                    </span>
                    @if ($canSwitchEnv)<x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--muted-foreground)" aria-hidden="true" />@endif
                </button>
                @if ($canSwitchEnv)
                    <div x-show="env" x-transition.opacity.duration.150ms @click.outside="env=false" x-cloak
                         class="cbx-panel" style="position:absolute;top:calc(100% + 6px);left:0;min-width:260px;z-index:40;box-shadow:var(--shadow-popover);padding:6px">
                        <p class="cbx-nav-group" style="padding:6px 10px 4px">Switch target</p>
                        @foreach ($environments as $env)
                            <form method="POST" action="{{ route('operator.environment.switch') }}">@csrf
                                <input type="hidden" name="environment" value="{{ $env->id }}">
                                <button type="submit" class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;{{ $env->id === $activeEnvId ? 'background:var(--secondary)' : '' }}">
                                    <x-icon name="layers" class="w-3.5 h-3.5 shrink-0" style="color:var(--muted-foreground)" />
                                    <span class="min-w-0 flex-1 text-left"><span class="block text-[13px] truncate">{{ $env->name }}</span><span class="block text-[11px] mono truncate" style="color:var(--muted-foreground)">{{ $env->slug }}</span></span>
                                    @if ($env->id === $activeEnvId)<x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" />@endif
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif
                </div>
            </div>
            <button type="button" data-theme-toggle class="cbx-subnav-toggle" aria-label="Toggle theme" title="Toggle theme"><x-icon name="sun" class="w-[18px] h-[18px]" /></button>
        </header>

        <main id="main-content" class="flex-1 overflow-y-auto canvas-gradient pb-16 lg:pb-0">
            <div class="p-6 lg:p-8 mx-auto w-full" style="max-width:72rem">{{ $slot }}</div>
        </main>
    </div>

    {{-- ═══ Thumb-anchored mobile nav (bottom bar + sheet); target-env switcher rides in the sheet ═══ --}}
    <x-mobile-nav :groups="$groups" :is-active="$isActive" heading="Operator" subheading="Platform console"
                  initial="OP" logout-route="operator.logout"
                  :member-name="$operator?->name ?? $operator?->email" member-email="Platform operator">
        <p class="cbx-nav-group px-2 pb-1">Target environment</p>
        @if ($canSwitchEnv)
            <div class="space-y-0.5 max-h-52 overflow-y-auto">
                @foreach ($environments as $env)
                    <form method="POST" action="{{ route('operator.environment.switch') }}">@csrf
                        <input type="hidden" name="environment" value="{{ $env->id }}">
                        <button type="submit" class="cbx-row w-full" style="padding:8px 10px;border-radius:8px;gap:10px;{{ $env->id === $activeEnvId ? 'background:var(--secondary)' : '' }}">
                            <x-icon name="layers" class="w-3.5 h-3.5 shrink-0" style="color:var(--muted-foreground)" />
                            <span class="min-w-0 flex-1 text-left"><span class="block text-[13px] truncate">{{ $env->name }}</span><span class="block text-[11px] mono truncate" style="color:var(--muted-foreground)">{{ $env->slug }}</span></span>
                            @if ($env->id === $activeEnvId)<x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--primary)" />@endif
                        </button>
                    </form>
                @endforeach
            </div>
        @else
            <div class="flex items-center gap-2 px-2 py-1.5">
                <x-icon name="layers" class="w-4 h-4 shrink-0" style="color:var(--primary)" aria-hidden="true" />
                <span class="text-[13px] font-semibold truncate">{{ $activeEnv?->name ?? 'None yet' }}</span>
            </div>
        @endif
    </x-mobile-nav>
</div>
    <x-toast />
</body>
</html>

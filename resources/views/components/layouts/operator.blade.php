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
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
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

<div class="flex h-full" x-data="{ nav: false, env: false }" @keydown.escape.window="nav=false;env=false">
    {{-- ═══ Desktop sidebar — 2-tier grouped ═══ --}}
    <aside class="hidden lg:flex flex-col shrink-0 w-60" style="background:var(--sidebar);border-right:1px solid var(--sidebar-border)">
        <div class="flex items-center gap-2.5 px-4 h-14 shrink-0" style="border-bottom:1px solid var(--sidebar-border)">
            <span class="grid place-items-center w-8 h-8 rounded-lg text-sm font-semibold shrink-0" style="background:var(--foreground);color:var(--background)" aria-hidden="true">OP</span>
            <span class="min-w-0">
                <span class="block text-[13px] font-semibold truncate leading-tight" style="font-family:var(--font-display)">Operator</span>
                <span class="block text-[11px] leading-tight" style="color:var(--muted-foreground)">Platform console</span>
            </span>
        </div>

        <nav class="flex-1 overflow-y-auto p-3 space-y-3" aria-label="Operator areas">
            @foreach ($groups as $group)
                <div class="space-y-0.5">
                    <p class="cbx-nav-group flex items-center gap-2 px-2 pb-1 text-[11px] font-semibold uppercase tracking-wide" style="color:var(--faint)">
                        <x-icon :name="$group['icon']" class="w-3.5 h-3.5" aria-hidden="true" /> {{ $group['label'] }}
                    </p>
                    @foreach ($group['pages'] as $page)
                        <a href="{{ route($page['route']) }}" class="nav-link {{ $isActive($page['route']) ? 'is-active' : '' }}"
                           @if ($isActive($page['route'])) aria-current="page" @endif>{{ $page['label'] }}</a>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <div class="p-3" style="border-top:1px solid var(--sidebar-border)">
            <div class="flex items-center gap-2 px-1 mb-2 min-w-0">
                <span class="grid place-items-center w-7 h-7 rounded-full text-xs font-semibold shrink-0" style="background:var(--accent-soft);color:var(--accent)" aria-hidden="true">{{ $operatorInitial }}</span>
                <div class="min-w-0">
                    <p class="text-[13px] font-medium truncate">{{ $operator?->name ?? $operator?->email }}</p>
                    <p class="text-[11px] truncate" style="color:var(--muted-foreground)">Platform operator</p>
                </div>
            </div>
            <button type="button" data-theme-toggle class="nav-link w-full"><x-icon name="moon" class="w-[1.15rem] h-[1.15rem]" /> Toggle theme</button>
            <form method="POST" action="{{ route('operator.logout') }}">@csrf
                <button type="submit" class="nav-link w-full" style="color:var(--destructive)"><x-icon name="logout" class="w-[1.15rem] h-[1.15rem]" /> Sign out</button>
            </form>
        </div>
    </aside>

    {{-- ═══ Main column ═══ --}}
    <div class="flex flex-col min-w-0 flex-1">
        {{-- Slim top bar — carries the operator's target-environment context (desktop). --}}
        <header class="hidden lg:flex cbx-topbar items-center justify-between">
            <div class="relative min-w-0">
                <button type="button" class="flex items-center gap-2 rounded-lg px-2 py-1.5 {{ $canSwitchEnv ? '' : 'pointer-events-none' }}"
                        style="transition:background-color var(--dur-hover) var(--ease)" @if ($canSwitchEnv) @click="env=!env" onmouseover="this.style.background='var(--secondary)'" onmouseout="this.style.background='transparent'" :aria-expanded="env" aria-haspopup="true" @endif>
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
            <button type="button" data-theme-toggle class="cbx-subnav-toggle" aria-label="Toggle theme" title="Toggle theme"><x-icon name="sun" class="w-[18px] h-[18px]" /></button>
        </header>

        @if (session('status'))
            <div role="status" aria-live="polite" class="mx-6 mt-4 rounded-lg px-4 py-3 text-sm"
                 style="background:var(--success-soft);color:var(--success);border:1px solid color-mix(in oklch,var(--success) 20%,transparent)">{{ session('status') }}</div>
        @endif

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
</body>
</html>

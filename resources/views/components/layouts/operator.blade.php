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

    $areas = [
        ['route' => 'operator.environments', 'label' => 'Environments', 'icon' => 'layers'],
        ['route' => 'operator.usage', 'label' => 'Usage', 'icon' => 'dashboard'],
        ['route' => 'operator.search', 'label' => 'Search', 'icon' => 'search'],
        ['route' => 'operator.organizations', 'label' => 'Organizations', 'icon' => 'directory'],
        ['route' => 'operator.operators', 'label' => 'Operators', 'icon' => 'members'],
        ['route' => 'operator.security', 'label' => 'Security', 'icon' => 'shield'],
    ];
    $operatorInitial = strtoupper(substr($operator?->name ?? $operator?->email ?? 'O', 0, 1));
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
</head>
<body class="h-full" style="background:var(--background);color:var(--foreground)">
<a href="#main-content" class="skip-link">Skip to content</a>

<div class="flex h-full" x-data="{
        pinned: {{ request()->cookie('cbox-nav-pinned') === '1' ? 'true' : 'false' }},
        mobile: false, account: false, env: false,
        togglePin() { this.pinned = !this.pinned; document.documentElement.classList.toggle('cbx-nav-pinned', this.pinned); document.cookie = 'cbox-nav-pinned=' + (this.pinned ? '1' : '0') + ';path=/;max-age=31536000;samesite=lax'; }
     }"
     @keydown.escape.window="mobile=false;account=false;env=false">

    {{-- ═══ TIER 1 — operator icon rail ═══ --}}
    <aside class="cbx-rail hidden lg:flex" :class="{ 'open': pinned }" aria-label="Operator areas">
        <div class="cbx-rail-hd" :style="pinned ? '' : 'justify-content:center'">
            <a href="{{ route('operator.environments') }}" class="cbx-rail-brand" title="Operator console" aria-label="Operator console">
                <svg viewBox="0 0 64 64" role="img" aria-hidden="true"><rect x="2" y="2" width="60" height="60" rx="14" fill="var(--foreground)"/><text x="32" y="44" text-anchor="middle" fill="var(--background)" font-family="var(--font-display)" font-weight="700" font-size="30" letter-spacing="-0.04em">OP</text></svg>
            </a>
        </div>

        <nav class="flex-1 overflow-y-auto" style="scrollbar-width:none">
            @foreach ($areas as $area)
                <a href="{{ route($area['route']) }}" title="{{ $area['label'] }}"
                   class="{{ request()->routeIs($area['route'].'*') ? 'cbx-on' : '' }}">
                    <x-icon :name="$area['icon']" class="w-[18px] h-[18px]" aria-hidden="true" />
                    <span class="lbl">{{ $area['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="cbx-rail-foot">
            <button type="button" class="cbx-railitem" @click="togglePin()" :title="pinned ? 'Collapse navigation' : 'Expand navigation'" aria-label="Toggle navigation width">
                <span class="inline-flex items-center justify-center shrink-0" style="width:18px;height:18px;transition:transform 150ms var(--ease)" :style="pinned ? 'transform:rotate(90deg)' : 'transform:rotate(-90deg)'">
                    <x-icon name="chevron" class="w-[18px] h-[18px]" />
                </span>
                <span class="lbl">Collapse</span>
            </button>
            <button type="button" class="cbx-railitem" @click="account=!account" title="{{ $operator?->name ?? $operator?->email }}" aria-haspopup="true" :aria-expanded="account">
                <span class="cbx-avatar" aria-hidden="true">{{ $operatorInitial }}</span>
                <span class="lbl" style="overflow:hidden;text-overflow:ellipsis">{{ $operator?->name ?? $operator?->email }}</span>
            </button>
            <div x-show="account" x-transition.opacity.duration.150ms @click.outside="account=false" x-cloak
                 class="cbx-panel" style="position:absolute;bottom:calc(100% + 8px);left:0;min-width:230px;z-index:75;box-shadow:var(--shadow-popover);padding:6px">
                <div style="padding:8px 10px;border-bottom:1px solid var(--border);margin-bottom:4px">
                    <p style="font-size:13px;font-weight:600;margin:0" class="truncate">{{ $operator?->name ?? $operator?->email }}</p>
                    <p style="font-size:12px;color:var(--muted-foreground);margin:2px 0 0">Platform operator</p>
                </div>
                <button type="button" data-theme-toggle class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;font-size:13px">
                    <x-icon name="moon" class="w-4 h-4" /> Toggle theme
                </button>
                <form method="POST" action="{{ route('operator.logout') }}">@csrf
                    <button type="submit" class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;font-size:13px;color:var(--destructive)">
                        <x-icon name="logout" class="w-4 h-4" /> Sign out
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- ═══ Mobile drawer ═══ --}}
    <div class="lg:hidden" x-cloak>
        <div x-show="mobile" x-transition.opacity class="fixed inset-0 z-40" style="background:rgb(0 0 0 / 0.5)" @click="mobile=false" aria-hidden="true"></div>
        <div x-show="mobile"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
             class="fixed inset-y-0 left-0 z-50 w-72 max-w-[85%] flex flex-col" style="background:var(--sidebar);border-right:1px solid var(--sidebar-border)"
             role="dialog" aria-modal="true" aria-label="Navigation">
            <div class="cbx-sidebar-brand" style="justify-content:space-between">
                <span class="text-sm font-semibold" style="font-family:var(--font-display)">Operator console</span>
                <button type="button" @click="mobile=false" class="cbx-subnav-toggle" aria-label="Close navigation"><x-icon name="close" class="w-[18px] h-[18px]" /></button>
            </div>
            <nav class="cbx-nav" aria-label="Primary">
                @foreach ($areas as $area)
                    <a href="{{ route($area['route']) }}" class="nav-link" @click="mobile=false" @if (request()->routeIs($area['route'].'*')) aria-current="page" @endif>
                        <x-icon :name="$area['icon']" class="w-[1.15rem] h-[1.15rem]" aria-hidden="true" />
                        {{ $area['label'] }}
                    </a>
                @endforeach
            </nav>
            <div class="p-3" style="border-top:1px solid var(--sidebar-border)">
                <div class="px-1 mb-2">
                    <p class="text-sm font-medium truncate">{{ $operator?->name ?? $operator?->email }}</p>
                    <p class="text-xs truncate" style="color:var(--muted-foreground)">Platform operator</p>
                </div>
                <button type="button" data-theme-toggle class="nav-link w-full"><x-icon name="moon" class="w-[1.15rem] h-[1.15rem]" /> Toggle theme</button>
                <form method="POST" action="{{ route('operator.logout') }}">@csrf<button type="submit" class="nav-link w-full"><x-icon name="logout" class="w-[1.15rem] h-[1.15rem]" /> Sign out</button></form>
            </div>
        </div>
    </div>

    {{-- ═══ Main column ═══ --}}
    <div class="flex flex-col min-w-0 flex-1">
        <header class="cbx-topbar">
            <div class="flex items-center gap-2 min-w-0">
                <button type="button" @click="mobile=true" class="cbx-subnav-toggle lg:hidden" aria-label="Open navigation"><x-icon name="menu" class="w-[18px] h-[18px]" /></button>
                <span class="text-[13px] font-semibold hidden sm:inline" style="font-family:var(--font-display)">Operator console</span>

                {{-- Target environment — what the console reads and provisions into. --}}
                <div class="relative sm:pl-3 sm:ml-1" style="border-left:0">
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
            </div>

            <div class="flex items-center gap-2">
                <button type="button" data-theme-toggle class="cbx-subnav-toggle" aria-label="Toggle theme" title="Toggle theme"><x-icon name="sun" class="w-[18px] h-[18px]" /></button>
            </div>
        </header>

        @if (session('status'))
            <div role="status" aria-live="polite" class="mx-6 mt-4 rounded-lg px-4 py-3 text-sm"
                 style="background:var(--success-soft);color:var(--success);border:1px solid color-mix(in oklch,var(--success) 20%,transparent)">{{ session('status') }}</div>
        @endif

        <main id="main-content" class="flex-1 overflow-y-auto canvas-gradient">
            <div class="p-6 lg:p-8 mx-auto w-full" style="max-width:72rem">{{ $slot }}</div>
        </main>
    </div>
</div>
</body>
</html>

@props(['title' => null])
@php
    use App\Http\Middleware\SetEnvironment;
    use App\Platform\OperatorAuth;

    $operator = app(OperatorAuth::class)->current();

    // Operators stand above every environment, so the target-plane selector lists
    // them all with no identity guard — switching just repoints reads/provisioning.
    $ctx = app(\Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext::class);
    $activeEnvId = $ctx->current()?->environmentKey();
    $environments = $ctx->withoutScope(fn () => \Cbox\Id\Organization\Models\Environment::query()
        ->orderBy('created_at')->get(['id', 'name', 'slug']));
    $activeEnv = $environments->firstWhere('id', $activeEnvId);

    $nav = [
        ['route' => 'operator.environments', 'label' => 'Environments', 'icon' => 'layers'],
        ['route' => 'operator.organizations', 'label' => 'Organizations', 'icon' => 'directory'],
        ['route' => 'operator.operators', 'label' => 'Operators', 'icon' => 'members'],
        ['route' => 'operator.security', 'label' => 'Security', 'icon' => 'shield'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ($title ? $title.' · ' : '').'Operator · '.config('cbox-id.branding.name', 'Cbox ID') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full" style="background:var(--bg);color:var(--text)">
<a href="#main-content" class="skip-link">Skip to content</a>

<div class="min-h-full lg:grid" style="grid-template-columns:15.5rem 1fr" x-data="{ nav: false }" @keydown.escape.window="nav = false">
    <aside class="hidden lg:flex flex-col border-r" aria-label="Sidebar" style="border-color:var(--border);background:var(--surface)">
        <div class="h-16 flex items-center gap-2 px-5 border-b" style="border-color:var(--border)">
            <span aria-hidden="true" class="grid place-items-center rounded-md text-xs font-bold shrink-0"
                  style="width:1.6rem;height:1.6rem;background:var(--accent);color:var(--accent-fg)">C</span>
            <span class="text-sm font-semibold">Operator console</span>
        </div>

        {{-- Target environment — what the console reads and provisions into. --}}
        <div class="px-3 pt-3">
            @if ($environments->count() > 1)
                <details class="env-switcher relative">
                    <summary class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 list-none cursor-pointer"
                             style="border:1px solid var(--border)"
                             aria-label="Target environment: {{ $activeEnv?->name }}. Switch">
                        <x-icon name="layers" class="w-4 h-4 shrink-0" style="color:var(--accent)" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-[0.6rem] font-medium uppercase tracking-wide" style="color:var(--faint)">Target environment</span>
                            <span class="block text-sm font-medium truncate">{{ $activeEnv?->name ?? 'Select…' }}</span>
                        </span>
                        <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" aria-hidden="true" />
                    </summary>
                    <div class="absolute left-0 right-0 mt-1 z-30 rounded-lg border p-1 shadow-lg"
                         style="background:var(--surface);border-color:var(--border)">
                        <p class="px-2 py-1 text-[0.68rem] font-medium uppercase tracking-wide" style="color:var(--faint)">Switch target</p>
                        @foreach ($environments as $env)
                            <form method="POST" action="{{ route('operator.environment.switch') }}">
                                @csrf
                                <input type="hidden" name="environment" value="{{ $env->id }}">
                                <button type="submit"
                                        class="w-full flex items-center gap-2.5 rounded-md px-2 py-1.5 text-left hover:opacity-80"
                                        style="{{ $env->id === $activeEnvId ? 'background:var(--surface-2)' : '' }}">
                                    <x-icon name="layers" class="w-3.5 h-3.5 shrink-0" style="color:var(--faint)" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm truncate">{{ $env->name }}</span>
                                        <span class="block text-xs font-mono truncate" style="color:var(--faint)">{{ $env->slug }}</span>
                                    </span>
                                    @if ($env->id === $activeEnvId)
                                        <x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--accent)" />
                                    @endif
                                </button>
                            </form>
                        @endforeach
                    </div>
                </details>
            @else
                <div class="flex items-center gap-2 rounded-lg px-2.5 py-1.5" style="border:1px solid var(--border)">
                    <x-icon name="layers" class="w-4 h-4 shrink-0" style="color:var(--accent)" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-[0.6rem] font-medium uppercase tracking-wide" style="color:var(--faint)">Target environment</span>
                        <span class="block text-sm font-medium truncate">{{ $activeEnv?->name ?? 'None yet' }}</span>
                    </span>
                </div>
            @endif
        </div>

        <nav class="flex-1 px-3 pt-3 space-y-0.5 overflow-y-auto" aria-label="Primary">
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}" class="nav-link"
                   @if (request()->routeIs($item['route'].'*')) aria-current="page" @endif>
                    <x-icon :name="$item['icon']" class="w-[1.15rem] h-[1.15rem]" aria-hidden="true" />
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="p-3 border-t space-y-1" style="border-color:var(--border)">
            <div class="px-2 py-1">
                <p class="text-sm font-medium truncate">{{ $operator?->name ?? $operator?->email }}</p>
                <p class="text-xs truncate" style="color:var(--faint)">Platform operator</p>
            </div>
            <button type="button" data-theme-toggle class="nav-link w-full">
                <x-icon name="moon" class="w-[1.15rem] h-[1.15rem] dark:hidden" />
                <span>Toggle theme</span>
            </button>
            <form method="POST" action="{{ route('operator.logout') }}">
                @csrf
                <button type="submit" class="nav-link w-full">
                    <x-icon name="logout" class="w-[1.15rem] h-[1.15rem]" />
                    <span>Sign out</span>
                </button>
            </form>
        </div>
    </aside>

    {{-- Mobile navigation drawer (off-canvas), shown below the lg breakpoint. --}}
    <div class="lg:hidden" x-cloak>
        <div x-show="nav" x-transition.opacity class="fixed inset-0 z-40" style="background:rgb(0 0 0 / 0.5)" @click="nav = false" aria-hidden="true"></div>
        <div x-show="nav"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
             class="fixed inset-y-0 left-0 z-50 w-72 max-w-[85%] flex flex-col border-r"
             style="border-color:var(--border);background:var(--surface)"
             role="dialog" aria-modal="true" aria-label="Navigation">
            <div class="h-16 flex items-center justify-between px-5 border-b" style="border-color:var(--border)">
                <span class="text-sm font-semibold">Operator console</span>
                <button type="button" @click="nav = false" class="btn btn-ghost" style="padding:0.4rem" aria-label="Close navigation">
                    <x-icon name="close" class="w-[1.1rem] h-[1.1rem]" />
                </button>
            </div>
            <div class="px-3 pt-3 text-xs" style="color:var(--faint)">
                <span class="uppercase tracking-wide">Target environment</span>
                <p class="text-sm font-medium truncate" style="color:var(--text)">{{ $activeEnv?->name ?? 'None yet' }}</p>
            </div>
            <nav class="flex-1 px-3 pt-3 space-y-0.5 overflow-y-auto" aria-label="Primary">
                @foreach ($nav as $item)
                    <a href="{{ route($item['route']) }}" class="nav-link" @click="nav = false"
                       @if (request()->routeIs($item['route'].'*')) aria-current="page" @endif>
                        <x-icon :name="$item['icon']" class="w-[1.15rem] h-[1.15rem]" aria-hidden="true" />
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
            <div class="p-3 border-t space-y-2" style="border-color:var(--border)">
                <div class="px-1">
                    <p class="text-sm font-medium truncate">{{ $operator?->name ?? $operator?->email }}</p>
                    <p class="text-xs truncate" style="color:var(--faint)">Platform operator</p>
                </div>
                <button type="button" data-theme-toggle class="nav-link w-full"><x-icon name="moon" class="w-[1.15rem] h-[1.15rem]" /> <span>Toggle theme</span></button>
                <form method="POST" action="{{ route('operator.logout') }}">
                    @csrf
                    <button type="submit" class="nav-link w-full"><x-icon name="logout" class="w-[1.15rem] h-[1.15rem]" /> <span>Sign out</span></button>
                </form>
            </div>
        </div>
    </div>

    <div class="flex flex-col min-w-0 overflow-x-hidden">
        <header class="lg:hidden h-16 flex items-center gap-3 px-5 border-b" style="border-color:var(--border);background:var(--surface)">
            <button type="button" @click="nav = true" class="btn btn-ghost" style="padding:0.4rem" aria-label="Open navigation">
                <x-icon name="menu" class="w-[1.15rem] h-[1.15rem]" />
            </button>
            <span class="text-sm font-semibold">Operator console</span>
        </header>

        @if (session('status'))
            <div role="status" aria-live="polite" class="mx-5 sm:mx-7 mt-4 rounded-lg px-4 py-3 text-sm"
                 style="background:var(--success-soft);color:var(--success);border:1px solid color-mix(in srgb,var(--success) 30%,transparent)">
                {{ session('status') }}
            </div>
        @endif

        <main id="main-content" class="flex-1 p-5 sm:p-7 max-w-6xl w-full">
            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>

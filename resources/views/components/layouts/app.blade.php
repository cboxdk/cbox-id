@props(['title' => null])
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · Cbox ID' : 'Cbox ID' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full" style="background:var(--bg);color:var(--text)">
@php
    $nav = [
        ['route' => 'dashboard', 'label' => 'Overview', 'icon' => 'dashboard'],
        ['route' => 'members', 'label' => 'Members', 'icon' => 'members'],
        ['route' => 'connections', 'label' => 'SSO connections', 'icon' => 'connections'],
        ['route' => 'directories', 'label' => 'Directory sync', 'icon' => 'directory'],
        ['route' => 'roles', 'label' => 'Roles', 'icon' => 'roles'],
        ['route' => 'clients', 'label' => 'API clients', 'icon' => 'clients'],
        ['route' => 'webhooks', 'label' => 'Webhooks', 'icon' => 'webhooks'],
        ['route' => 'audit', 'label' => 'Audit log', 'icon' => 'audit'],
        ['route' => 'settings', 'label' => 'Settings', 'icon' => 'settings'],
    ];

    // Organizations the signed-in subject belongs to, for the switcher.
    $myOrgs = collect();
    if ($me->check()) {
        $orgRepo = app(\Cbox\Id\Organization\Contracts\Organizations::class);
        $myOrgs = app(\Cbox\Id\Organization\Contracts\Memberships::class)
            ->forUser($me->id())
            ->map(fn ($m) => (object) [
                'id' => $m->organization_id,
                'role' => $m->role ?? null,
                'name' => $orgRepo->find($m->organization_id)?->name,
            ])
            ->filter(fn ($o) => $o->name !== null)
            ->values();
    }
    $activeOrgId = $me->organization()?->id;
@endphp

<a href="#main-content" class="skip-link">Skip to content</a>

<div class="min-h-full lg:grid" style="grid-template-columns:15.5rem 1fr">
    <aside class="hidden lg:flex flex-col border-r" aria-label="Sidebar" style="border-color:var(--border);background:var(--surface)">
        <div class="h-16 flex items-center px-5 border-b" style="border-color:var(--border)">
            <a href="{{ route('dashboard') }}"><x-brand /></a>
        </div>

        <div class="px-3 py-3">
            @php $canSwitch = $myOrgs->count() > 1; @endphp
            <details class="org-switcher relative" @if (! $canSwitch) open-disabled @endif>
                <summary class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 list-none {{ $canSwitch ? 'cursor-pointer' : '' }}"
                         style="background:var(--surface-2)"
                         @if ($canSwitch) aria-label="Current organization: {{ $me->organization()?->name }}. Switch organization" @else onclick="return false" @endif>
                    <span aria-hidden="true" class="grid place-items-center rounded-md text-xs font-bold shrink-0"
                          style="width:1.75rem;height:1.75rem;background:var(--accent);color:var(--accent-fg)">
                        {{ strtoupper(substr($me->organization()?->name ?? 'C', 0, 1)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold truncate">{{ $me->organization()?->name ?? 'No organization' }}</p>
                        <p class="text-xs truncate" style="color:var(--faint)">{{ $me->role() ? ucfirst($me->role()) : 'Member' }}</p>
                    </div>
                    @if ($canSwitch)
                        <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" aria-hidden="true" />
                    @endif
                </summary>

                @if ($canSwitch)
                    <div class="absolute left-0 right-0 mt-1 z-20 rounded-lg border p-1 shadow-lg"
                         style="background:var(--surface);border-color:var(--border)">
                        <p class="px-2 py-1 text-[0.68rem] font-medium uppercase tracking-wide" style="color:var(--faint)">Switch organization</p>
                        @foreach ($myOrgs as $o)
                            <form method="POST" action="{{ route('organization.switch') }}">
                                @csrf
                                <input type="hidden" name="organization" value="{{ $o->id }}">
                                <button type="submit"
                                        class="w-full flex items-center gap-2.5 rounded-md px-2 py-1.5 text-left hover:opacity-80"
                                        style="{{ $o->id === $activeOrgId ? 'background:var(--surface-2)' : '' }}">
                                    <span class="grid place-items-center rounded-md text-[0.65rem] font-bold shrink-0"
                                          style="width:1.5rem;height:1.5rem;background:var(--accent);color:var(--accent-fg)">
                                        {{ strtoupper(substr($o->name, 0, 1)) }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm truncate">{{ $o->name }}</span>
                                        <span class="block text-xs truncate" style="color:var(--faint)">{{ $o->role ? ucfirst($o->role) : 'Member' }}</span>
                                    </span>
                                    @if ($o->id === $activeOrgId)
                                        <x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--accent)" />
                                    @endif
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </details>
        </div>

        <nav class="flex-1 px-3 space-y-0.5 overflow-y-auto" aria-label="Primary">
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}" class="nav-link"
                   @if (request()->routeIs($item['route'].'*')) aria-current="page" @endif>
                    <x-icon :name="$item['icon']" class="w-[1.15rem] h-[1.15rem]" aria-hidden="true" />
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="p-3 border-t" style="border-color:var(--border)">
            <button type="button" data-theme-toggle class="nav-link w-full">
                <x-icon name="moon" class="w-[1.15rem] h-[1.15rem] dark:hidden" />
                <span>Toggle theme</span>
            </button>
        </div>
    </aside>

    <div class="flex flex-col min-w-0">
        <header class="h-16 flex items-center justify-between gap-4 px-5 sm:px-7 border-b sticky top-0 z-10"
                style="border-color:var(--border);background:color-mix(in srgb, var(--bg) 85%, transparent);backdrop-filter:blur(8px)">
            <div class="min-w-0">
                <h1 class="text-base font-semibold truncate">{{ $title ?? 'Overview' }}</h1>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" data-theme-toggle class="btn btn-ghost" style="padding:0.4rem" aria-label="Toggle theme">
                    <x-icon name="sun" class="w-[1.1rem] h-[1.1rem]" />
                </button>
                <div class="flex items-center gap-2.5 pl-3 border-l" style="border-color:var(--border)">
                    <span aria-hidden="true" class="grid place-items-center rounded-full text-xs font-semibold"
                          style="width:2rem;height:2rem;background:var(--accent-soft);color:var(--accent)">
                        {{ strtoupper(substr($me->name(), 0, 1)) }}
                    </span>
                    <div class="hidden sm:block min-w-0">
                        <p class="text-sm font-medium truncate leading-tight">{{ $me->name() }}</p>
                        <p class="text-xs truncate" style="color:var(--faint)">{{ $me->email() }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost" style="padding:0.4rem" aria-label="Sign out" title="Sign out">
                            <x-icon name="logout" class="w-[1.1rem] h-[1.1rem]" />
                        </button>
                    </form>
                </div>
            </div>
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

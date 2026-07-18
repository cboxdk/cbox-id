<?php

declare(strict_types=1);

use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Overview. Env-scoped counts across the whole
 * environment — the account-member admin's at-a-glance view of their IdP. Every
 * query here is confined to the host-resolved environment by the framework's hard
 * scope, so it can only ever count THIS environment's resources.
 */
new #[Layout('components.layouts.environment', ['title' => 'Overview'])] class extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'organizations' => Organization::query()->count(),
            'users' => User::query()->count(),
            'connections' => Connection::query()->count(),
        ];
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Overview</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Everything in this environment — organizations, users, and sign-in connections.</p>

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        @foreach ([['Organizations', $organizations, 'environment.organizations'], ['Users', $users, 'environment.users'], ['Connections', $connections, null]] as [$label, $count, $route])
            <div class="rounded-xl border p-5" style="border-color:var(--border)">
                <p class="text-sm" style="color:var(--muted)">{{ $label }}</p>
                <p class="mt-1 font-semibold tabular-nums" style="font-size:1.75rem">{{ number_format($count) }}</p>
                @if ($route)
                    <a href="{{ route($route) }}" class="mt-2 inline-block text-xs underline underline-offset-2" style="color:var(--accent)">Manage →</a>
                @endif
            </div>
        @endforeach
    </div>
</div>

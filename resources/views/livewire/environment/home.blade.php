<?php

declare(strict_types=1);

use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Enums\OrganizationStatus;
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
            'stats' => [
                ['label' => 'Organizations', 'count' => Organization::query()->where('status', '!=', OrganizationStatus::Deleted->value)->count(), 'route' => 'environment.organizations'],
                ['label' => 'Users', 'count' => User::query()->where('status', UserStatus::Active->value)->count(), 'route' => 'environment.users'],
                ['label' => 'SSO connections', 'count' => Connection::query()->count(), 'route' => 'environment.connections'],
                ['label' => 'Applications', 'count' => Client::query()->count(), 'route' => 'environment.clients'],
                ['label' => 'Directories', 'count' => Directory::query()->count(), 'route' => 'environment.directories'],
            ],
        ];
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Overview</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Everything in this environment — organizations, users, and sign-in.</p>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($stats as $stat)
            <a href="{{ route($stat['route']) }}" class="rounded-xl border p-5 transition-colors hover:bg-[var(--surface-2)]" style="border-color:var(--border)">
                <p class="text-sm" style="color:var(--muted)">{{ $stat['label'] }}</p>
                <p class="mt-1 font-semibold tabular-nums" style="font-size:1.75rem">{{ number_format($stat['count']) }}</p>
                <span class="mt-2 inline-block text-xs" style="color:var(--accent)">Manage →</span>
            </a>
        @endforeach
    </div>

    <div class="mt-8">
        <p class="text-xs font-semibold uppercase tracking-wide" style="color:var(--faint)">Quick actions</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <a href="{{ route('environment.organizations.create') }}" class="btn btn-ghost btn-sm"><x-icon name="plus" class="w-4 h-4" /> New organization</a>
            <a href="{{ route('environment.users.create') }}" class="btn btn-ghost btn-sm"><x-icon name="plus" class="w-4 h-4" /> New user</a>
            <a href="{{ route('environment.connections.create') }}" class="btn btn-ghost btn-sm"><x-icon name="plus" class="w-4 h-4" /> New SSO connection</a>
            <a href="{{ route('environment.clients.create') }}" class="btn btn-ghost btn-sm"><x-icon name="plus" class="w-4 h-4" /> New application</a>
        </div>
    </div>
</div>

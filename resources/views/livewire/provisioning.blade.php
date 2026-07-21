<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Outbound provisioning'])] class extends Component
{
    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('required|url|max:500')]
    public string $baseUrl = '';

    #[Validate('required|string|in:bearer,oauth2_client_credentials')]
    public string $scheme = 'bearer';

    #[Validate('required|string|max:4096')]
    public string $secret = '';

    public bool $creating = false;

    public function boot(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    public function register(ProvisioningConnections $connections): void
    {
        $this->validate();

        $connections->register(
            $this->orgId(),
            $this->name,
            $this->baseUrl,
            AuthScheme::from($this->scheme),
            $this->secret,
        );

        $this->reset('name', 'baseUrl', 'scheme', 'secret', 'creating');
        $this->dispatch('toast', message: 'Provisioning connection registered.');
    }

    public function pause(string $connectionId, ProvisioningConnections $connections): void
    {
        $connection = ProvisioningConnection::query()
            ->whereKey($connectionId)
            ->where('organization_id', $this->orgId())
            ->first();

        if ($connection === null) {
            return;
        }

        $connections->pause($connectionId);
        $this->dispatch('toast', message: 'Connection paused.');
    }

    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
            'connections' => ProvisioningConnection::query()
                ->where('organization_id', $this->orgId())
                ->orderByDesc('id')
                ->get(),
        ];
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }
}; ?>

<div>
    <div class="cbx-page-header mb-8">
        <div>
            <p class="cbx-page-eyebrow">Authentication</p>
            <h1 class="cbx-page-title">Outbound provisioning</h1>
            <p class="cbx-page-desc">Push users out to your downstream SaaS apps over their SCIM 2.0 endpoints. Changes here are provisioned to each connected app.</p>
        </div>
        @if ($me->isAdmin())
            <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> Register connection</button>
        @endif
    </div>

    @if ($creating && $me->isAdmin())
        <form wire:submit="register" class="card p-4 mb-5 space-y-4">
            <div>
                <label class="label" for="name">Name</label>
                <input wire:model="name" id="name" class="input" placeholder="Downstream app" autofocus>
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="baseUrl">SCIM base URL</label>
                <input wire:model="baseUrl" id="baseUrl" type="url" class="input" placeholder="https://app.example.com/scim/v2">
                @error('baseUrl') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="scheme">Auth scheme</label>
                <select wire:model="scheme" id="scheme" class="input">
                    <option value="bearer">Bearer token</option>
                    <option value="oauth2_client_credentials">OAuth2 client credentials</option>
                </select>
                @error('scheme') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="secret">Secret</label>
                <input wire:model="secret" id="secret" type="password" class="input" placeholder="Bearer token or OAuth client secret" autocomplete="new-password">
                @error('secret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                <p class="text-xs mt-1" style="color:var(--faint)">Sealed at rest and never shown again. Supply the downstream app's bearer token or OAuth client secret.</p>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Register</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th scope="col">Name</th><th scope="col">Base URL</th><th scope="col">Auth</th><th scope="col">Status</th><th scope="col"></th></tr>
                </thead>
                <tbody>
                    @forelse ($connections as $connection)
                        <tr>
                            <td>
                                <p class="font-medium">{{ $connection->name }}</p>
                                @if ($connection->last_error)
                                    <p class="text-xs" style="color:var(--muted)">{{ $connection->last_error }}</p>
                                @endif
                            </td>
                            <td class="mono text-xs max-w-[18rem] truncate" style="color:var(--muted)">{{ $connection->base_url }}</td>
                            <td><span class="badge mono">{{ $connection->auth_scheme->value }}</span></td>
                            <td>
                                @if ($connection->status === \Cbox\Id\Provisioning\Enums\ConnectionStatus::Active)
                                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span> Active</span>
                                @else
                                    <span class="cbx-pill cbx-pill--warning"><span class="dot"></span> Paused</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($me->isAdmin() && $connection->status === \Cbox\Id\Provisioning\Enums\ConnectionStatus::Active)
                                    <button wire:click="pause('{{ $connection->id }}')"
                                            wire:confirm="Pause this connection? It will stop provisioning changes to the downstream app."
                                            class="btn btn-ghost btn-sm">Pause</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="cbx-empty">
                                    <div class="cbx-empty-icon"><x-icon name="connections" class="w-5 h-5" /></div>
                                    <h3>No provisioning connections yet</h3>
                                    <p>Register a connection to start pushing user changes out to a downstream app over its SCIM endpoint.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php

declare(strict_types=1);

use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\ConnectionStatus;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Outbound sync › detail. The full, deep-linkable
 * lifecycle for one downstream SCIM target: connection config, pause/resume and
 * delete.
 *
 * Every read and mutation re-resolves the target within THIS environment (the
 * connection's BelongsToEnvironment scope) and 404s otherwise — an id from another
 * plane never matches (deny-by-default). Pause goes through the audited
 * {@see ProvisioningConnections} service; the service exposes no resume, so an
 * activate is a direct status write on the env-scoped model (mirroring how the org
 * detail page persists status changes with no dedicated service call). Delete is a
 * hard delete of the env-scoped model (no delete service exists), mirroring
 * organizations/show. The sealed secret is never re-echoed.
 */
new #[Layout('components.layouts.environment', ['title' => 'Outbound connection'])] class extends Component
{
    public string $syncId = '';

    public function mount(string $sync): void
    {
        $model = ProvisioningConnection::query()->whereKey($sync)->first();
        abort_if($model === null, 404);

        $this->syncId = $model->id;
    }

    private function connection(): ProvisioningConnection
    {
        $model = ProvisioningConnection::query()->whereKey($this->syncId)->first();
        abort_if($model === null, 404);

        return $model;
    }

    public function pause(ProvisioningConnections $connections): void
    {
        $connections->pause($this->connection()->id);
        session()->flash('status', 'Connection paused.');
    }

    public function resume(): void
    {
        $connection = $this->connection();
        $connection->status = ConnectionStatus::Active;
        $connection->save();

        session()->flash('status', 'Connection resumed.');
    }

    public function deleteConnection(): mixed
    {
        $this->connection()->delete();

        session()->flash('status', 'Connection deleted.');

        return $this->redirectRoute('environment.provisioning', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $connection = $this->connection();

        return [
            'connection' => $connection,
            'organizationName' => $connection->organization_id !== null
                ? Organization::query()->whereKey($connection->organization_id)->value('name')
                : null,
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.provisioning') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Outbound sync</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $connection->name }}</h1>
            @if ($connection->status === ConnectionStatus::Active)
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Active</span>
            @else
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Paused</span>
            @endif
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $connection->id }}</p>
    </div>

    {{-- Connection --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Connection</p>
        <div class="mt-4 space-y-4">
            <div>
                <p class="label">SCIM base URL</p>
                <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $connection->base_url }}</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <p class="label">Auth scheme</p>
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $connection->auth_scheme->value }}</span>
                </div>
                <div>
                    <p class="label">Scope</p>
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $connection->organization_id === null ? 'Environment-wide' : ($organizationName ?? 'Org-scoped') }}</span>
                </div>
            </div>
            @if ($connection->last_error)
                <div>
                    <p class="label">Last error</p>
                    <p class="text-sm" style="color:var(--destructive)">{{ $connection->last_error }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- Lifecycle --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Lifecycle</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($connection->status === ConnectionStatus::Active)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="pause" wire:confirm="Pause this connection? It will stop provisioning changes to the downstream app.">Pause</button>
            @else
                <button type="button" class="btn btn-ghost btn-sm" wire:click="resume">Resume</button>
            @endif
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="deleteConnection" wire:confirm="Delete this connection? It stops provisioning and cannot be undone.">Delete connection</button>
        </div>
    </div>
</div>

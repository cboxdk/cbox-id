<?php

declare(strict_types=1);

use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Event hooks — the external inline-hook registry.
 * Registers the customer HTTPS endpoints the platform calls synchronously at a
 * {@see HookPoint} to enrich or veto an operation.
 *
 * Endpoints are environment-owned (BelongsToEnvironment), so every query and every
 * mutation resolves only within this environment — an id from another plane never
 * matches, closing cross-tenant id tampering. Access is gated by the env-admin
 * session (env.admin route middleware), so the account member has full CRUD here;
 * there is no per-org entitlement lock at the control-plane level. An endpoint may
 * be scoped to a single organization or left environment-wide (null organization).
 */
new #[Layout('components.layouts.environment', ['title' => 'Event hooks'])] class extends Component
{
    public string $hook = 'token_minting';

    /** Empty string is the sentinel for "environment-wide" (null organization). */
    public string $organization_id = '';

    #[Validate('required|url|max:500')]
    public string $url = '';

    public bool $creating = false;

    /** The reveal-once plaintext signing secret, shown only right after registration. */
    public ?string $newSecret = null;

    public function register(ExternalActions $actions): void
    {
        $this->validate();

        $registered = $actions->register(
            HookPoint::from($this->hook),
            $this->url,
            $this->organization_id !== '' ? $this->organization_id : null,
        );

        $this->newSecret = $registered->secret;

        $this->reset('url', 'creating', 'organization_id');
        $this->hook = 'token_minting';

        session()->flash('status', 'Event hook endpoint registered.');
    }

    public function pause(string $endpointId, ExternalActions $actions): void
    {
        abort_if(ExternalActionEndpoint::query()->find($endpointId) === null, 404);

        $actions->pause($endpointId);

        session()->flash('status', 'Endpoint paused.');
    }

    public function activate(string $endpointId, ExternalActions $actions): void
    {
        abort_if(ExternalActionEndpoint::query()->find($endpointId) === null, 404);

        $actions->activate($endpointId);

        session()->flash('status', 'Endpoint activated.');
    }

    public function remove(string $endpointId, ExternalActions $actions): void
    {
        abort_if(ExternalActionEndpoint::query()->find($endpointId) === null, 404);

        $actions->remove($endpointId);

        session()->flash('status', 'Endpoint removed.');
    }

    public function dismissSecret(): void
    {
        $this->reset('newSecret');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $organizations = Organization::query()->orderBy('name')->get();

        return [
            'organizations' => $organizations,
            'orgNames' => $organizations->pluck('name', 'id'),
            'hookPoints' => HookPoint::cases(),
            'rows' => ExternalActionEndpoint::query()->orderByDesc('id')->get(),
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Event hooks</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">External endpoints the platform calls synchronously at a hook point to enrich or veto an operation.</p>
        </div>
        <button wire:click="$toggle('creating')" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> Register endpoint</button>
    </div>

    @if ($newSecret)
        <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border);background:var(--accent-soft)">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-semibold text-sm" style="color:var(--accent)">Copy this signing secret now — it won't be shown again.</p>
                    <p class="mono text-xs rounded-lg px-3 py-2 mt-3 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $newSecret }}</p>
                    <p class="mt-3 text-xs" style="color:var(--faint)">The endpoint verifies the <code class="mono">X-Cbox-Signature</code> header on each hook request with this secret.</p>
                </div>
                <button wire:click="dismissSecret" class="btn btn-ghost btn-sm shrink-0">Dismiss</button>
            </div>
        </div>
    @endif

    @if ($creating)
        <form wire:submit="register" class="mt-6 rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="hook">Hook point</label>
                    <select wire:model="hook" id="hook" class="select">
                        @foreach ($hookPoints as $hookPoint)
                            <option value="{{ $hookPoint->value }}">{{ $hookPoint->name }}</option>
                        @endforeach
                    </select>
                    @error('hook') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="organization_id">Organization</label>
                    <select wire:model="organization_id" id="organization_id" class="select">
                        <option value="">All organizations (environment-wide)</option>
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                        @endforeach
                    </select>
                    @error('organization_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label" for="url">Endpoint URL</label>
                <input wire:model="url" id="url" type="url" class="input mono" placeholder="https://example.com/hooks/token" autofocus>
                @error('url') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Register endpoint</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($rows as $endpoint)
            <div class="rounded-xl border p-5" style="border-color:var(--border)">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs rounded-full px-2 py-0.5 mono" style="background:var(--surface-2);color:var(--muted)">{{ $endpoint->hook_point->value }}</span>
                            @if ($endpoint->status === \Cbox\Id\ExternalActions\Enums\ActionEndpointStatus::Active)
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Active</span>
                            @else
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Paused</span>
                            @endif
                            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $endpoint->organization_id !== null ? ($orgNames[$endpoint->organization_id] ?? $endpoint->organization_id) : 'Environment-wide' }}</span>
                        </div>
                        <p class="mt-2 mono text-xs break-all" style="color:var(--muted)">{{ $endpoint->url }}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        @if ($endpoint->status === \Cbox\Id\ExternalActions\Enums\ActionEndpointStatus::Active)
                            <button wire:click="pause('{{ $endpoint->id }}')"
                                    wire:confirm="Pause this endpoint? It will stop being called at the hook point."
                                    class="btn btn-ghost btn-sm">Pause</button>
                        @else
                            <button wire:click="activate('{{ $endpoint->id }}')"
                                    class="btn btn-ghost btn-sm">Activate</button>
                        @endif
                        <button wire:click="remove('{{ $endpoint->id }}')"
                                wire:confirm="Remove this endpoint? This cannot be undone."
                                class="btn btn-ghost btn-sm" style="color:var(--destructive)">Remove</button>
                    </div>
                </div>
            </div>
        @empty
            <p class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">No event hook endpoints yet. Register one to have the platform call your external logic at a hook point.</p>
        @endforelse
    </div>
</div>

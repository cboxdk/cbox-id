<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Inline hooks'])] class extends Component
{
    public string $hook = 'token_minting';

    #[Validate('required|url|max:500')]
    public string $url = '';

    public bool $creating = false;

    public ?string $newSecret = null;

    public function boot(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    public function register(ExternalActions $actions): void
    {
        $this->validate();

        $registered = $actions->register(HookPoint::from($this->hook), $this->url, $this->orgId());

        $this->newSecret = $registered->secret;

        $this->reset('url', 'creating');
        $this->hook = 'token_minting';
        $this->dispatch('toast', message: 'Inline hook endpoint registered.');
    }

    public function pause(string $endpointId, ExternalActions $actions): void
    {
        $actions->pause($endpointId, $this->orgId());
        $this->dispatch('toast', message: 'Endpoint paused.');
    }

    public function activate(string $endpointId, ExternalActions $actions): void
    {
        $actions->activate($endpointId, $this->orgId());
        $this->dispatch('toast', message: 'Endpoint activated.');
    }

    public function remove(string $endpointId, ExternalActions $actions): void
    {
        $actions->remove($endpointId, $this->orgId());
        $this->dispatch('toast', message: 'Endpoint removed.');
    }

    public function dismissSecret(): void
    {
        $this->reset('newSecret');
    }

    public function with(): array
    {
        $orgId = $this->orgId();

        return [
            'me' => app(CurrentUser::class),
            'rows' => ExternalActionEndpoint::query()
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $orgId))
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
            <p class="cbx-page-eyebrow">Developers</p>
            <h1 class="cbx-page-title">Inline hooks</h1>
            <p class="cbx-page-desc">External endpoints the platform calls synchronously at a hook point to enrich or veto an operation.</p>
        </div>
        <div class="flex items-center gap-2">
            @if ($me->isAdmin())
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> Register endpoint</button>
            @endif
        </div>
    </div>

    @if ($newSecret)
        <div class="card p-4 mb-5" style="border-color:color-mix(in srgb, var(--warning) 40%, transparent);background:color-mix(in srgb, var(--warning) 8%, transparent)">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-semibold text-sm" style="color:var(--warning-strong)">Copy this signing secret now — it won't be shown again.</p>
                    <p class="mt-3 select-all break-all mono text-sm">{{ $newSecret }}</p>
                    <p class="mt-3 text-xs" style="color:var(--faint)">The endpoint verifies the <span class="mono">X-Cbox-Signature</span> header on each hook request with this secret.</p>
                </div>
                <button wire:click="dismissSecret" class="btn btn-ghost btn-sm">Dismiss</button>
            </div>
        </div>
    @endif

    @if ($creating && $me->isAdmin())
        <form wire:submit="register" class="card p-4 mb-5">
            <div class="mb-4">
                <label class="label" for="hook">Hook point</label>
                <select wire:model="hook" id="hook" class="input">
                    <option value="token_minting">Token minting</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="label" for="url">Endpoint URL</label>
                <input wire:model="url" id="url" type="url" class="input" placeholder="https://example.com/hooks/token" autofocus>
                @error('url') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center gap-3 mt-4">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Register endpoint</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th scope="col">Hook point</th><th scope="col">URL</th><th scope="col">Status</th><th scope="col"></th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $endpoint)
                        <tr>
                            <td><span class="badge mono">{{ $endpoint->hook_point->value }}</span></td>
                            <td class="mono break-all text-xs" style="color:var(--muted)">{{ $endpoint->url }}</td>
                            <td>
                                @if ($endpoint->status === \Cbox\Id\ExternalActions\Enums\ActionEndpointStatus::Active)
                                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span> Active</span>
                                @else
                                    <span class="cbx-pill cbx-pill--warning"><span class="dot"></span> Paused</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($me->isAdmin())
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
                                            class="btn btn-ghost btn-sm" style="color:var(--danger)">Remove</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="cbx-empty">
                                    <div class="cbx-empty-icon"><x-icon name="webhooks" class="w-5 h-5" /></div>
                                    <h3>No inline hook endpoints yet</h3>
                                    <p>Register an endpoint to have the platform call your external logic at a hook point.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

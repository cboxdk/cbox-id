<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'API clients'])] class extends Component
{
    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('nullable|string|max:500')]
    public string $scopes = '';

    public bool $creating = false;

    public ?string $newClientId = null;

    public ?string $newSecret = null;

    public function create(ClientRegistry $clients): void
    {
        $this->validate();

        $registered = $clients->register(new NewClient(
            name: $this->name,
            type: ClientType::Confidential,
            scopes: $this->parsedScopes(),
            organizationId: $this->orgId(),
        ));

        $this->newClientId = $registered->client->client_id;
        $this->newSecret = $registered->secret;

        $this->reset('name', 'scopes', 'creating');
        session()->flash('status', 'API client "'.$registered->client->name.'" created.');
    }

    public function dismissSecret(): void
    {
        $this->reset('newClientId', 'newSecret');
    }

    /**
     * @return list<string>
     */
    private function parsedScopes(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', $this->scopes),
        ), fn (string $scope): bool => $scope !== ''));
    }

    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
            'rows' => Client::query()
                ->where('organization_id', $this->orgId())
                ->orderByDesc('id')
                ->get(),
        ];
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    public function boot(): void
    {
        // Read/write gate: this page exposes org-wide config (client secrets shown
        // once) — admins only. Enforced in boot() so it re-runs on every Livewire
        // action (create, toggle), not just the initial mount.
        $this->authorizeAdmin();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }
}; ?>

<div>
    <div class="cbx-page-header mb-8">
        <div>
            <p class="cbx-page-eyebrow">Developers</p>
            <h1 class="cbx-page-title">API clients</h1>
            <p class="cbx-page-desc">OAuth clients that authenticate machine-to-machine access for this organization.</p>
        </div>
        <div class="flex items-center gap-2">
            @if ($me->isAdmin())
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New client</button>
            @endif
        </div>
    </div>

    @if ($newSecret)
        <div class="card p-4 mb-5" style="border-color:color-mix(in srgb, var(--warn) 40%, transparent);background:var(--warn-soft)">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-semibold text-sm" style="color:var(--warn)">Copy this secret now — it won't be shown again.</p>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div>
                            <dt class="text-xs" style="color:var(--muted)">Client ID</dt>
                            <dd class="mono break-all">{{ $newClientId }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs" style="color:var(--muted)">Client secret</dt>
                            <dd class="mono break-all">{{ $newSecret }}</dd>
                        </div>
                    </dl>
                </div>
                <button wire:click="dismissSecret" class="btn btn-ghost btn-sm">Dismiss</button>
            </div>
        </div>
    @endif

    @if ($creating && $me->isAdmin())
        <form wire:submit="create" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[14rem]">
                <label class="label" for="name">Name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="Billing service" autofocus>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1 min-w-[14rem]">
                <label class="label" for="scopes">Scopes</label>
                <input wire:model="scopes" id="scopes" type="text" class="input" placeholder="users.read, orgs.read">
                @error('scopes') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create client</button>
            <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
        </form>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th scope="col">Name</th><th scope="col">Client ID</th><th scope="col">Type</th><th scope="col">Scopes</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $client)
                        <tr>
                            <td class="font-medium">{{ $client->name }}</td>
                            <td class="mono text-xs" style="color:var(--muted)">{{ $client->client_id }}</td>
                            <td><span class="badge">{{ ucfirst($client->type->value) }}</span></td>
                            <td>
                                @if (count($client->scopes) > 0)
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($client->scopes as $scope)
                                            <span class="badge mono">{{ $scope }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <span style="color:var(--faint)">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="cbx-empty">
                                    <div class="cbx-empty-icon"><x-icon name="clients" class="w-5 h-5" /></div>
                                    <h3>No API clients yet</h3>
                                    <p>Register an OAuth client to authenticate machine-to-machine access for this organization.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

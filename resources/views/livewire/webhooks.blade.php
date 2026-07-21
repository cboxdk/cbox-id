<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Webhooks'])] class extends Component
{
    /** @var list<string> */
    public const EVENT_TYPES = [
        'user.created',
        'user.login',
        'identity.linked',
        'organization.member_added',
        'organization.member_removed',
        'directory.user.provisioned',
        'directory.user.deactivated',
    ];

    #[Validate('required|url|max:500')]
    public string $url = '';

    /** @var list<string> */
    #[Validate(['eventTypes' => 'required|array|min:1', 'eventTypes.*' => 'string'])]
    public array $eventTypes = [];

    public bool $creating = false;

    public ?string $newSecret = null;

    public function create(WebhookRegistry $webhooks): void
    {
        $this->authorizeAdmin();
        $this->validate();

        $registered = $webhooks->register($this->orgId(), $this->url, array_values($this->eventTypes));

        $this->newSecret = $registered->secret;

        $this->reset('url', 'eventTypes', 'creating');
        $this->dispatch('toast', message: 'Webhook endpoint created.');
    }

    public function pause(string $endpointId, WebhookRegistry $webhooks): void
    {
        $this->authorizeAdmin();

        $endpoint = WebhookEndpoint::query()
            ->whereKey($endpointId)
            ->where('organization_id', $this->orgId())
            ->first();

        if ($endpoint === null) {
            return;
        }

        $webhooks->pause($endpointId, $this->orgId());
        $this->dispatch('toast', message: 'Endpoint paused.');
    }

    public function dismissSecret(): void
    {
        $this->reset('newSecret');
    }

    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
            'rows' => WebhookEndpoint::query()
                ->where('organization_id', $this->orgId())
                ->orderByDesc('id')
                ->get(),
        ];
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    public function mount(): void
    {
        // Read gate: these pages expose org-wide config (client secrets shown
        // once, SSO connection settings, directory tokens, audit) — admins only.
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
            <h1 class="cbx-page-title">Webhooks</h1>
            <p class="cbx-page-desc">Endpoints that receive signed event notifications for this organization.</p>
        </div>
        <div class="flex items-center gap-2">
            @if ($me->isAdmin())
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> Add endpoint</button>
            @endif
        </div>
    </div>

    @if ($newSecret)
        <div class="card p-4 mb-5" style="border-color:color-mix(in srgb, var(--warn) 40%, transparent);background:var(--warn-soft)">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-semibold text-sm" style="color:var(--warn)">Copy this signing secret now — it won't be shown again.</p>
                    <p class="mt-3 mono break-all text-sm">{{ $newSecret }}</p>
                </div>
                <button wire:click="dismissSecret" class="btn btn-ghost btn-sm">Dismiss</button>
            </div>
        </div>
    @endif

    @if ($creating && $me->isAdmin())
        <form wire:submit="create" class="card p-4 mb-5">
            <div class="mb-4">
                <label class="label" for="url">Endpoint URL</label>
                <input wire:model="url" id="url" type="url" class="input" placeholder="https://example.com/webhooks/cbox" autofocus>
                @error('url') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <span class="label">Event types</span>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (self::EVENT_TYPES as $event)
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" wire:model="eventTypes" value="{{ $event }}">
                            <span class="mono">{{ $event }}</span>
                        </label>
                    @endforeach
                </div>
                @error('eventTypes') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center gap-3 mt-4">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create endpoint</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th scope="col">URL</th><th scope="col">Events</th><th scope="col">Status</th><th scope="col"></th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $endpoint)
                        <tr>
                            <td class="mono text-xs max-w-[18rem] truncate" style="color:var(--muted)">{{ $endpoint->url }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($endpoint->event_types as $event)
                                        <span class="badge mono">{{ $event }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                @if ($endpoint->status === \Cbox\Id\Webhooks\Enums\EndpointStatus::Active)
                                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span> Active</span>
                                @else
                                    <span class="cbx-pill cbx-pill--warning"><span class="dot"></span> Paused</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($me->isAdmin() && $endpoint->status === \Cbox\Id\Webhooks\Enums\EndpointStatus::Active)
                                    <button wire:click="pause('{{ $endpoint->id }}')"
                                            wire:confirm="Pause this endpoint? It will stop receiving events."
                                            class="btn btn-ghost btn-sm">Pause</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="cbx-empty">
                                    <div class="cbx-empty-icon"><x-icon name="webhooks" class="w-5 h-5" /></div>
                                    <h3>No webhook endpoints yet</h3>
                                    <p>Add an endpoint to start receiving signed event notifications for this organization.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

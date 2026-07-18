<?php

declare(strict_types=1);

use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Event hooks › New. A dedicated, deep-linkable create page.
 * Registers a customer HTTPS endpoint the platform calls synchronously at a
 * {@see HookPoint}; the endpoint may be scoped to a single organization or left
 * environment-wide (null organization). Registration mints a signing secret that is
 * returned exactly once — we hand it to the detail page as a one-time flash and route
 * straight there.
 */
new #[Layout('components.layouts.environment', ['title' => 'New event hook'])] class extends Component
{
    public string $hook = 'token_minting';

    /** Empty string is the sentinel for "environment-wide" (null organization). */
    public string $organization_id = '';

    #[Validate('required|url|max:500')]
    public string $url = '';

    public function create(ExternalActions $actions): mixed
    {
        $this->validate();

        $registered = $actions->register(
            HookPoint::from($this->hook),
            $this->url,
            $this->organization_id !== '' ? $this->organization_id : null,
        );

        // The plaintext secret exists only in this response; hand it to the detail page
        // as a one-time flash — it is never retrievable again.
        session()->flash('newSecret', $registered->secret);
        session()->flash('status', 'Event hook endpoint registered.');

        return $this->redirectRoute('environment.hooks.show', ['hook' => $registered->endpoint->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'organizations' => Organization::query()->orderBy('name')->get(),
            'hookPoints' => HookPoint::cases(),
        ];
    }
}; ?>

<div>
    <a href="{{ route('environment.hooks') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Event hooks</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New event hook</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">The signing secret is shown once, right after you register the endpoint.</p>

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="hook">Hook point</label>
            <select wire:model="hook" id="hook" class="select">
                @foreach ($hookPoints as $hookPoint)
                    <option value="{{ $hookPoint->value }}">{{ $hookPoint->name }}</option>
                @endforeach
            </select>
            @error('hook') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="organization_id">Organization</label>
            <select wire:model="organization_id" id="organization_id" class="select">
                <option value="">All organizations (environment-wide)</option>
                @foreach ($organizations as $organization)
                    <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                @endforeach
            </select>
            @error('organization_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="url">Endpoint URL</label>
            <input wire:model="url" id="url" type="url" class="input mono" placeholder="https://example.com/hooks/token" autofocus>
            @error('url') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Register endpoint</button>
            <a href="{{ route('environment.hooks') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>

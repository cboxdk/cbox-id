<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\Console\VaultScope;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Console › Token vault › New — one component, both planes. A dedicated, deep-linkable
 * create page for a downstream credential. The value is handled in the clear this one
 * time and sealed on store (Crypto SecretBox); it is NEVER echoed back afterwards.
 *
 * THERE IS NO SCOPE PICKER ON THE FORM ANY MORE. The environment plane's version carried
 * a second organization dropdown, so the console had two places saying whose secret this
 * is — the rail's acting-organization picker, and a select inside one form — and the
 * organization plane had neither because its answer was implicit. The console already has
 * one answer to "which organization am I acting on"; the secret is stored for that one.
 * An environment administrator who wants a tenant's secret chooses that tenant the same
 * way they do for every other page, which is also the choice the audit trail records.
 */
new #[Layout('components.layouts.console', ['title' => 'New stored token'])] class extends Component
{
    /**
     * Second layer — see the index page. boot(), not mount(), so it re-runs on every
     * Livewire message rather than only the first render.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('required|string|max:190')]
    public string $provider = '';

    #[Validate('required|string')]
    public string $secret = '';

    public function store(SecretVault $vault): mixed
    {
        app(ConsoleScope::class)->assertMayAdminister();

        $this->validateOnly('name');
        $this->validateOnly('provider');
        $this->validateOnly('secret');

        $model = $vault->store($this->name, $this->provider, $this->secret, app(VaultScope::class)->owner());

        $this->dispatch('toast', message: 'Secret sealed and stored — its value is never shown again.');

        return $this->redirectRoute(
            app(ConsoleScope::class)->routeName('vault.show'),
            ['secret' => $model->id],
            navigate: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);

        return [
            // `organizationName()` rather than indexing the whole map: this needs ONE
            // name, and the map is the size of the environment. A tenant with a few
            // thousand organizations hydrated every one of them to label a single form.
            'scopeLabel' => $scope->organizationId() === null
                ? 'this environment'
                : ($scope->organizationName() ?? 'this organization'),
        ];
    }
}; ?>

<div>
    <a href="{{ route(app(\App\Platform\Console\ConsoleScope::class)->routeName('vault')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Token vault</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New stored token</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">A downstream API key your apps and agents present to a provider. It is sealed on store and brokered only to explicitly granted clients.</p>

    <form wire:submit="store" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="name">Name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="openai-prod" autofocus>
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="provider">Provider</label>
                <input wire:model="provider" id="provider" type="text" class="input" placeholder="openai">
                @error('provider') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="label" for="secret">Secret value</label>
                <input wire:model="secret" id="secret" type="password" class="input mono" placeholder="sk-live-…" autocomplete="off">
                @error('secret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- The scope, stated rather than chosen: it is the console's acting organization,
             which the rail already shows and the audit trail already records. --}}
        <p class="text-sm" style="color:var(--muted)">This secret will belong to <span class="font-medium" style="color:var(--foreground)">{{ $scopeLabel }}</span>.</p>

        {{-- Write-only handling: the value is handled in the clear this one time and
             sealed on store — it is never echoed back, so warn before submitting. --}}
        <div class="rounded-xl border p-5" style="border-color:color-mix(in oklch,var(--warning) 35%,transparent);background:var(--warning-soft);color:var(--warning-strong)">
            <h2 class="cbx-section-title">This is the only time the value is handled in the clear.</h2>
            <p class="mt-1 text-xs">It is sealed on store and never shown again — keep your own copy if you need one.</p>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="store">Seal &amp; store</button>
            <a href="{{ route(app(\App\Platform\Console\ConsoleScope::class)->routeName('vault')) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>

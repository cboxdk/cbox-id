<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Event hooks › detail. The full, deep-linkable lifecycle
 * for one external inline-hook endpoint: its registration details, pause/activate, the
 * one-time signing secret handed off from the create page, and delete.
 *
 * Every read/mutation re-resolves the endpoint within THIS environment (the model's
 * BelongsToEnvironment scope) and 404s otherwise — an id from another plane never
 * matches (deny-by-default). The signing secret is stored sealed and never decrypted
 * for display; only the freshly minted secret handed off at creation is shown, exactly
 * once, straight from the flash bag — it is never stored in a public prop or re-echoed.
 */
new #[Layout('components.layouts.environment', ['title' => 'Event hook'])] class extends Component
{
    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->current() === null, 403);
    }

    public string $endpointId = '';

    public function mount(string $hook): void
    {
        $endpoint = ExternalActionEndpoint::query()->whereKey($hook)->first();
        abort_if($endpoint === null, 404);

        $this->endpointId = $endpoint->id;
    }

    private function endpoint(): ExternalActionEndpoint
    {
        $endpoint = ExternalActionEndpoint::query()->whereKey($this->endpointId)->first();
        abort_if($endpoint === null, 404);

        return $endpoint;
    }

    /*
     * The acting scope is the endpoint's OWN org: this is the environment plane, whose
     * admin is the operator ABOVE the orgs in it, so managing an org-scoped hook here is
     * legitimate. The real boundary is EnvironmentScope — an endpoint in another
     * environment is not visible to this console at all. The tenant-facing /hooks console
     * is where the per-org restriction bites.
     */
    public function pause(ExternalActions $actions): void
    {
        $actions->pause($this->endpoint()->id, $this->endpoint()->organization_id);
        $this->dispatch('toast', message: 'Endpoint paused — it will stop being called at the hook point.');
    }

    public function activate(ExternalActions $actions): void
    {
        $actions->activate($this->endpoint()->id, $this->endpoint()->organization_id);
        $this->dispatch('toast', message: 'Endpoint activated.');
    }

    public function remove(ExternalActions $actions): mixed
    {
        $actions->remove($this->endpoint()->id, $this->endpoint()->organization_id);
        $this->dispatch('toast', message: 'Endpoint removed.');

        return $this->redirectRoute('environment.hooks', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $endpoint = $this->endpoint();

        $orgName = $endpoint->organization_id !== null
            ? (Organization::query()->whereKey($endpoint->organization_id)->value('name') ?? $endpoint->organization_id)
            : null;

        return [
            'endpoint' => $endpoint,
            'orgName' => $orgName,
            // One-time reveal handed off from the create page — read straight from the
            // flash bag so it survives only this initial render and is never stored.
            'newSecret' => session('newSecret'),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.hooks') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Event hooks</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight mono truncate" style="font-size:1.25rem">{{ $endpoint->url }}</h1>
            @if ($endpoint->status === \Cbox\Id\ExternalActions\Enums\ActionEndpointStatus::Active)
                <span class="badge badge-success">Active</span>
            @else
                <span class="badge badge-warn">Paused</span>
            @endif
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $endpoint->id }}</p>
    </div>

    {{-- One-time signing secret handed off from the create page — never shown again. --}}
    @if ($newSecret)
        <div class="rounded-xl border p-5" style="border-color:color-mix(in oklch, var(--warning) 40%, transparent);background:var(--warning-soft)">
            <div class="min-w-0">
                <p class="text-sm font-semibold" style="color:var(--warning-strong)">Copy this signing secret now — it won't be shown again.</p>
                <p class="mt-3 mono text-sm break-all select-all">{{ $newSecret }}</p>
                <p class="mt-3 text-xs" style="color:var(--warning-strong)">The endpoint verifies the <code class="mono">X-Cbox-Signature</code> header on each hook request with this secret.</p>
            </div>
        </div>
    @endif

    {{-- Details --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Details</p>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="label">Hook point</dt>
                <dd class="mt-1"><span class="badge mono">{{ $endpoint->hook_point->value }}</span></dd>
            </div>
            <div>
                <dt class="label">Organization</dt>
                <dd class="mt-1"><span class="badge">{{ $orgName ?? 'All organizations' }}</span></dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="label">Endpoint URL</dt>
                <dd class="mt-1 mono text-sm break-all" style="color:var(--muted)">{{ $endpoint->url }}</dd>
            </div>
        </dl>
    </div>

    {{-- Lifecycle --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Lifecycle</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($endpoint->status === \Cbox\Id\ExternalActions\Enums\ActionEndpointStatus::Active)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="pause" wire:confirm="Pause this endpoint? It will stop being called at the hook point.">Pause</button>
            @else
                <button type="button" class="btn btn-ghost btn-sm" wire:click="activate">Activate</button>
            @endif
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="remove" wire:confirm="Remove this endpoint? This cannot be undone.">Delete</button>
        </div>
    </div>
</div>

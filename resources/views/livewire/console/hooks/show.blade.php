<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Inline hooks › detail. The full, deep-linkable lifecycle for one endpoint:
 * its registration details, pause/activate, the one-time signing secret handed off from
 * the create page, and remove.
 *
 * Every read/mutation re-resolves the endpoint within THIS environment (the model's
 * BelongsToEnvironment scope) AND within what the acting scope may see, then 404s
 * otherwise — an id from another plane or another tenant never matches
 * (deny-by-default). The signing secret is stored sealed and never decrypted for
 * display; only the freshly minted secret handed off at creation is shown, exactly once.
 */
new #[Layout('components.layouts.console', ['title' => 'Inline hook'])] class extends Component
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
        app(ConsoleScope::class)->assertMayAdminister();
    }

    public string $endpointId = '';

    /**
     * The one-time signing secret, handed off from the create page's flash bag.
     *
     * Protected, so Livewire never dehydrates it into the wire:snapshot embedded in the
     * DOM — the plaintext lives only in the response that reveals it. Read in mount()
     * rather than in with(), because the flash is aged out at the end of this request:
     * read per-render it would vanish on the first unrelated action instead of when the
     * administrator says so.
     */
    protected ?string $newSecret = null;

    public function mount(string $hook): void
    {
        $this->endpointId = $hook;

        // Resolves through the same visibility rule every later read uses, so an id the
        // acting scope may not see 404s here rather than at the first action.
        $this->endpoint();

        $secret = session('newSecret');
        $this->newSecret = is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * The endpoint, re-resolved and re-scoped on every read and write.
     *
     * The organization plane GAINED a detail page in this merge, and an unscoped
     * whereKey() on that plane would hand a tenant admin any other tenant's endpoint URL
     * by id — the environment plane never had to care, because its administrator sits
     * above every organization here. Endpoints the environment itself owns
     * (organization null) stay visible to a tenant: they fire on that tenant's sign-ins.
     * 404 rather than 403, because the caller was not entitled to learn it exists.
     */
    private function endpoint(): ExternalActionEndpoint
    {
        $organizationId = app(ConsoleScope::class)->organizationId();

        $endpoint = ExternalActionEndpoint::query()
            ->whereKey($this->endpointId)
            ->when($organizationId !== null, fn ($q) => $q->where(
                fn ($scoped) => $scoped->whereNull('organization_id')->orWhere('organization_id', $organizationId)
            ))
            ->first();

        abort_if($endpoint === null, 404);

        return $endpoint;
    }

    public function pause(ExternalActions $actions): void
    {
        $endpoint = $this->manageable();

        $actions->pause($endpoint->id, $endpoint->organization_id);
        $this->dispatch('toast', message: 'Endpoint paused — it will stop being called at the hook point.');
    }

    public function activate(ExternalActions $actions): void
    {
        $endpoint = $this->manageable();

        $actions->activate($endpoint->id, $endpoint->organization_id);
        $this->dispatch('toast', message: 'Endpoint activated.');
    }

    public function remove(ExternalActions $actions): mixed
    {
        $endpoint = $this->manageable();

        $actions->remove($endpoint->id, $endpoint->organization_id);
        $this->dispatch('toast', message: 'Endpoint removed.');

        return $this->redirectRoute(app(ConsoleScope::class)->routeName('hooks'), navigate: true);
    }

    /**
     * Clear the revealed signing secret.
     *
     * The organization console had this and the environment console did not. The banner
     * shows a live credential in plaintext, and an administrator who has copied it — or
     * who is sharing a screen — needs it gone; leaving it up until the next navigation
     * is not an answer. By the time this runs the secret is already off the server, so
     * making the banner go is the whole job.
     */
    public function dismissSecret(): void
    {
        $this->newSecret = null;
    }

    /**
     * The endpoint, refused unless this administrator may CHANGE it.
     *
     * {@see ExternalActions} already answers a mismatched acting organization with a
     * silent no-op — the caller was not entitled to learn the endpoint exists — but a
     * silent no-op reached through this page would still redirect and announce "Endpoint
     * removed" over an endpoint that is still running. So the refusal is explicit here
     * and the contract's no-op stays the backstop it was written to be.
     */
    private function manageable(): ExternalActionEndpoint
    {
        $endpoint = $this->endpoint();

        abort_unless($this->mayManage($endpoint), 403);

        return $endpoint;
    }

    /**
     * Whether this administrator may change this endpoint, as opposed to look at it.
     *
     * On the ENVIRONMENT plane the administrator is the operator ABOVE every
     * organization in it, so every endpoint this console can see is theirs to manage;
     * EnvironmentScope is the real boundary and an endpoint in another environment is
     * not visible here at all. On the ORGANIZATION plane it is the member's own
     * organization and nothing else — which is precisely what makes the environment's
     * own hooks visible to a tenant admin and not manageable by one.
     */
    private function mayManage(ExternalActionEndpoint $endpoint): bool
    {
        $scope = app(ConsoleScope::class);

        return $scope->plane() === ConsolePlane::Environment
            || $scope->organizationId() === $endpoint->organization_id;
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
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'endpoint' => $endpoint,
            'orgName' => $orgName,
            // Surfaced through render, not a public prop, so the minted secret is never
            // dehydrated into the wire:snapshot embedded in the DOM.
            'newSecret' => $this->newSecret,
            // A tenant admin sees the environment's own hooks because they fire on their
            // own sign-ins, but may not touch them — so the buttons are not offered,
            // rather than offered and refused.
            'mayManage' => $this->mayManage($endpoint),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route($scopeRoute('hooks')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Inline hooks</a>
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
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-sm font-semibold" style="color:var(--warning-strong)">Copy this signing secret now — it won't be shown again.</p>
                    <p class="mt-3 mono text-sm break-all select-all">{{ $newSecret }}</p>
                    <p class="mt-3 text-xs" style="color:var(--warning-strong)">The endpoint verifies the <code class="mono">X-Cbox-Signature</code> header on each hook request with this secret.</p>
                </div>
                <button type="button" wire:click="dismissSecret" class="btn btn-ghost btn-sm shrink-0">Dismiss</button>
            </div>
        </div>
    @endif

    {{-- Details --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Details</h2>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="label">Hook point</dt>
                <dd class="mt-1"><span class="badge" title="{{ $endpoint->hook_point->description() }}">{{ $endpoint->hook_point->label() }}</span></dd>
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
        <h2 class="cbx-section-title">Lifecycle</h2>
        @if ($mayManage)
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($endpoint->status === \Cbox\Id\ExternalActions\Enums\ActionEndpointStatus::Active)
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="pause" wire:confirm="Pause this endpoint? It will stop being called at the hook point.">Pause</button>
                @else
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="activate">Activate</button>
                @endif
                <x-confirm-delete
                    :name="$endpoint->url"
                    action="remove"
                    label="Remove"
                    consequence="The hook stops being called and its signing secret is destroyed. This cannot be undone." />
            </div>
        @else
            <p class="mt-4 text-sm" style="color:var(--muted)">This endpoint belongs to the environment and fires for every organization in it. Your operator manages it.</p>
        @endif
    </div>
</div>

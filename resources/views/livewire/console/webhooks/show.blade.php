<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\Console\WebhookEventCatalogue;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Enums\EndpointStatus;
use Cbox\Id\Webhooks\Models\WebhookDelivery;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Cbox\Id\Webhooks\Support\SafeWebhookUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Webhooks › detail. The full, deep-linkable lifecycle for one endpoint: edit
 * the URL and subscribed events, pause and resume, rotate the signing secret, inspect
 * recent deliveries, and delete.
 *
 * One component, both planes. The organization plane had none of this — its console
 * could create an endpoint and pause it, and a tenant administrator who paused one had
 * no way to start it again, change what it listened for, re-key it after a leak, or take
 * it away. All six live here now, on both planes.
 *
 * Every read and mutation re-resolves the endpoint within THIS environment (the model's
 * BelongsToEnvironment scope) AND within what the acting scope may see, then 404s
 * otherwise — see {@see self::endpoint()}. The signing secret is stored sealed and never
 * decrypted for display; only a freshly minted secret (create hand-off or rotation) is
 * shown, exactly once, and never re-echoed.
 */
new #[Layout('components.layouts.console', ['title' => 'Webhook'])] class extends Component
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

    public string $editUrl = '';

    /**
     * Livewire rehydrates this straight off the wire, so the keys are whatever the
     * request sent — not necessarily a gapless list. Hence the array_values() before
     * it is handed on.
     *
     * @var array<array-key, string>
     */
    public array $editEvents = [];

    /**
     * The freshly minted secret shown once (create hand-off or rotation).
     *
     * Protected, so Livewire never dehydrates it into the wire:snapshot embedded in the
     * DOM — the plaintext lives only in the response that reveals it.
     */
    protected ?string $newSecret = null;

    public function mount(string $webhook): void
    {
        $this->endpointId = $webhook;

        // Resolves through the same visibility rule every later read uses, so an id the
        // acting scope may not see 404s here rather than at the first action.
        $endpoint = $this->endpoint();

        $this->editUrl = $endpoint->url;
        $this->editEvents = array_values($endpoint->event_types);

        // One-time reveal handed off from the create page. Read in mount() rather than in
        // with(), because the flash is aged out at the end of this request: read
        // per-render it would vanish on the first unrelated action instead of when the
        // administrator says so.
        $secret = session('newSecret');
        $this->newSecret = is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * The endpoint, re-resolved and re-scoped on every read and write.
     *
     * The organization plane GAINED a detail page in this merge, and the environment
     * plane's whereKey() on the primary key alone was safe only because an environment
     * administrator — who sits above every organization here — was its sole caller.
     * Serving the same component on the organization plane with that lookup would hand a
     * tenant administrator every other tenant's endpoint by id, and with it rotateSecret:
     * a live signing secret for somebody else's receiver, minted on demand. Endpoints the
     * environment itself owns (organization null) stay VISIBLE to a tenant because they
     * receive that tenant's events — see {@see self::mayManage()} for why visible is not
     * manageable. 404 rather than 403, because the caller was not entitled to learn it
     * exists.
     */
    private function endpoint(): WebhookEndpoint
    {
        $organizationId = $this->actingOrganizationId();

        $endpoint = WebhookEndpoint::query()
            ->whereKey($this->endpointId)
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $scoped): Builder => $scoped->whereNull('organization_id')->orWhere('organization_id', $organizationId)
            ))
            ->first();

        abort_if($endpoint === null, 404);

        return $endpoint;
    }

    /**
     * The organization this page acts within, or null for the whole-environment view.
     *
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both, and the
     * first is what opens {@see self::endpoint()} to the whole environment. On the
     * organization plane that second case would do the same, which is the escalation this
     * page exists to refuse, so it is a refusal rather than an unscoped query.
     */
    private function actingOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        return $scope->plane() === ConsolePlane::Environment
            ? $scope->organizationId()
            : $scope->requireOrganizationId();
    }

    /**
     * Change the URL and the subscribed events.
     */
    public function saveSubscription(): void
    {
        $endpoint = $this->manageable();

        $this->validate([
            'editUrl' => ['required', 'url', 'max:500'],
            'editEvents' => ['required', 'array', 'min:1'],
            'editEvents.*' => ['string'],
        ]);

        // Re-run the SSRF guard on any URL change — a public endpoint can never be
        // silently repointed at an internal address.
        if (! SafeWebhookUrl::isSafe($this->editUrl)) {
            $this->addError('editUrl', 'That URL is not allowed — it must be a public HTTPS endpoint.');

            return;
        }

        $endpoint->url = $this->editUrl;
        $endpoint->event_types = array_values($this->editEvents);
        $endpoint->save();

        $this->dispatch('toast', message: 'Subscription updated.');
    }

    public function pause(WebhookRegistry $webhooks): void
    {
        $endpoint = $this->manageable();

        // Acted in the endpoint's OWN scope, which is what the registry matches on:
        // an environment administrator is the operator above the organizations here, so
        // passing the endpoint's organization (null for the environment's own) is the
        // only call that resolves for both planes.
        $webhooks->pause($endpoint->id, $endpoint->organization_id);
        $this->dispatch('toast', message: 'Endpoint paused — it will stop receiving events.');
    }

    /**
     * Start a paused endpoint again.
     *
     * The organization console had no resume at all, so a tenant administrator who
     * paused an endpoint could not start it again from their own console — the one
     * action whose absence turns a reversible pause into a one-way door. The registry
     * exposes no resume, so this is a direct status write on the already-scoped model,
     * mirroring how the outbound-sync detail page persists the same change.
     */
    public function resume(): void
    {
        $endpoint = $this->manageable();

        $endpoint->status = EndpointStatus::Active;
        $endpoint->save();

        $this->dispatch('toast', message: 'Endpoint resumed.');
    }

    /**
     * Re-key the endpoint, revealing the new secret exactly once.
     *
     * The sharpest reason {@see self::endpoint()} is scoped: run against another
     * tenant's endpoint this hands over a live signing secret, with which anything can
     * be forged into their receiver.
     *
     * And the reason it is BEHIND A STEP-UP: scoping decides WHOSE secret this hands over,
     * not whether the person at the keyboard is still the administrator. On the
     * environment plane {@see mayManage()} returns an unconditional true, so the scope
     * refuses nothing here — a hijacked or unattended session is the whole threat, and a
     * fresh password is the only thing that answers it. On the ACTION rather than the
     * route, because this page is also where deliveries are inspected.
     */
    public function rotateSecret(SecretBox $secretBox): void
    {
        // Authorization first: a step-up in front of a 403 would hand somebody who may not
        // touch this endpoint a password prompt instead of a refusal.
        $endpoint = $this->manageable();

        $sudo = app(ConsoleStepUp::class)->challenge(
            'webhooks.show',
            'environment.webhooks.show',
            ['webhook' => $this->endpointId],
        );

        if ($sudo !== null) {
            $this->redirectRoute($sudo, navigate: false);

            return;
        }

        $secret = bin2hex(random_bytes(32));
        $endpoint->secret_encrypted = $secretBox->seal($secret, $endpoint->secretContext());
        $endpoint->save();

        // Shown once here; the sealed form is all that persists.
        $this->newSecret = $secret;
        $this->dispatch('toast', message: 'Signing secret rotated — update your endpoint now.');
    }

    /**
     * Clear the revealed signing secret.
     *
     * The banner shows a live credential in plaintext, and an administrator who has
     * copied it — or who is sharing a screen — needs it gone; leaving it up until the
     * next navigation is not an answer. By the time this runs the secret is already off
     * the server, so making the banner go is the whole job.
     */
    public function dismissSecret(): void
    {
        $this->newSecret = null;
    }

    public function deleteEndpoint(): mixed
    {
        $this->manageable()->delete();
        $this->dispatch('toast', message: 'Webhook endpoint deleted.');

        return $this->redirectRoute(app(ConsoleScope::class)->routeName('webhooks'), navigate: true);
    }

    /**
     * The endpoint, refused unless this administrator may CHANGE it.
     *
     * Resolved inside the gate rather than checked afterwards, so every mutation on this
     * page shares one refusal instead of five that have to agree.
     */
    private function manageable(): WebhookEndpoint
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
     * EnvironmentScope is the real boundary and an endpoint in another environment is not
     * visible here at all. On the ORGANIZATION plane it is the member's own organization
     * and nothing else — which is what makes the environment's own platform-wide
     * endpoint visible to a tenant admin and not touchable by one.
     */
    private function mayManage(WebhookEndpoint $endpoint): bool
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
        $mayManage = $this->mayManage($endpoint);

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'endpoint' => $endpoint,
            'orgName' => $endpoint->organization_id !== null
                ? (Organization::query()->whereKey($endpoint->organization_id)->value('name') ?? $endpoint->organization_id)
                : null,
            'eventCatalogue' => WebhookEventCatalogue::EVENTS,
            // A tenant admin sees the environment's own endpoint because it receives
            // their events, but may not touch it — so the controls are not offered,
            // rather than offered and refused.
            'mayManage' => $mayManage,
            // Deliveries are withheld on the same rule. A platform-wide endpoint's
            // delivery log is a record of what happened in EVERY organization here, so
            // rendering it under a tenant's login would leak the other tenants' activity
            // through a page they are only meant to be able to notice.
            'deliveries' => $mayManage
                ? WebhookDelivery::query()
                    ->where('endpoint_id', $endpoint->id)
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
                : new Collection,
            // Surfaced through render, not a public prop, so the minted secret is never
            // dehydrated into the wire:snapshot embedded in the DOM.
            'newSecret' => $this->newSecret,
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route($scopeRoute('webhooks')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Webhooks</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight mono truncate" style="font-size:1.25rem">{{ $endpoint->url }}</h1>
            @if ($endpoint->status === \Cbox\Id\Webhooks\Enums\EndpointStatus::Active)
                <span class="badge badge-success">Active</span>
            @else
                <span class="badge badge-warn">Paused</span>
            @endif
            <span class="badge">{{ $orgName ?? 'All organizations' }}</span>
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $endpoint->id }}</p>
    </div>

    {{-- One-time signing secret (create hand-off or rotation) — never shown again. --}}
    @if ($newSecret)
        <div class="rounded-xl border p-5" style="border-color:color-mix(in oklch, var(--warning) 40%, transparent);background:var(--warning-soft)">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-sm font-semibold" style="color:var(--warning-strong)">Copy this signing secret now — it won't be shown again.</p>
                    <p class="mt-3 mono text-sm break-all select-all">{{ $newSecret }}</p>
                    <p class="mt-3 text-xs" style="color:var(--warning-strong)">Every delivery carries an HMAC computed with this secret — verify it before you trust the payload.</p>
                </div>
                <button type="button" wire:click="dismissSecret" class="btn btn-ghost btn-sm shrink-0">Dismiss</button>
            </div>
        </div>
    @endif

    {{-- Subscription --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Subscription</h2>
        @if ($mayManage)
            <form wire:submit="saveSubscription" class="mt-4 space-y-4">
                <div>
                    <label class="label" for="editUrl">Endpoint URL</label>
                    <input @error('editUrl') aria-invalid="true" aria-describedby="editUrl-error" @enderror wire:model="editUrl" id="editUrl" type="url" class="input mono">
                    @error('editUrl') <p id="editUrl-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <span class="label">Event types</span>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($eventCatalogue as $event)
                            <label wire:key="event-{{ $event }}" class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" wire:model="editEvents" value="{{ $event }}" class="rounded" @error('editEvents') aria-invalid="true" aria-describedby="editEvents-error" @enderror>
                                <span class="mono text-xs">{{ $event }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('editEvents') <p id="editEvents-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveSubscription">Save changes</button>
            </form>
        @else
            <div class="mt-4 flex flex-wrap gap-1.5">
                @foreach ($endpoint->event_types as $event)
                    <span class="badge mono">{{ $event }}</span>
                @endforeach
            </div>
            <p class="mt-4 text-sm" style="color:var(--muted)">This endpoint belongs to the environment and receives every organization's events, including yours. Your operator manages it.</p>
        @endif
    </div>

    @if ($mayManage)
        {{-- Signing secret --}}
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Signing secret</h2>
            <p class="mt-1 text-sm" style="color:var(--muted)">The secret signs every delivery's HMAC. It is stored sealed and can't be retrieved — rotating issues a new one, shown once.</p>
            <div class="mt-4">
                <x-confirm-delete
                    :name="$endpoint->url"
                    action="rotateSecret"
                    label="Rotate secret"
                    verb="Rotate"
                    trigger-class="btn btn-ghost btn-sm"
                    consequence="The current signing secret stops verifying immediately and cannot be recovered — your receiver rejects every delivery until it is updated.">
                    <x-icon name="refresh" class="w-4 h-4" /> Rotate secret
                </x-confirm-delete>
            </div>
        </div>

        {{-- Recent deliveries --}}
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Recent deliveries</h2>
            <div class="mt-4 space-y-2">
                @forelse ($deliveries as $delivery)
                    <div class="flex items-center gap-3 rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="delivery-{{ $delivery->id }}">
                        <div class="min-w-0 flex-1">
                            <span class="block text-sm font-medium truncate mono">{{ $delivery->event_type }}</span>
                            <p class="text-xs" style="color:var(--faint)">
                                Attempt {{ $delivery->attempt }}@if ($delivery->response_code) · HTTP {{ $delivery->response_code }}@endif
                                @if ($delivery->delivered_at) · delivered {{ $delivery->delivered_at->diffForHumans() }}@elseif ($delivery->next_retry_at) · retry {{ $delivery->next_retry_at->diffForHumans() }}@endif
                            </p>
                        </div>
                        @if ($delivery->status === \Cbox\Id\Webhooks\Enums\DeliveryStatus::Delivered)
                            <span class="badge badge-success shrink-0">Delivered</span>
                        @else
                            <span class="badge badge-warn shrink-0">{{ ucfirst($delivery->status->value) }}</span>
                        @endif
                    </div>
                @empty
                    <p class="text-sm" style="color:var(--muted)">No deliveries yet.</p>
                @endforelse
            </div>
        </div>

        {{-- Lifecycle --}}
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Lifecycle</h2>
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($endpoint->status === \Cbox\Id\Webhooks\Enums\EndpointStatus::Active)
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="pause" wire:confirm="Pause this endpoint? It will stop receiving events until resumed.">Pause</button>
                @else
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="resume">Resume</button>
                @endif
                <x-confirm-delete
                    :name="$endpoint->url"
                    action="deleteEndpoint"
                    label="Delete endpoint"
                    consequence="This endpoint stops receiving events and its delivery history is dropped. This cannot be undone." />
            </div>
        </div>
    @endif
</div>

<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\Console\WebhookEventCatalogue;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Exceptions\UnsafeWebhookUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Webhooks › New. A dedicated, deep-linkable create page. Registration mints a
 * signing secret that is returned exactly once, so we hand it to the detail page as a
 * one-time flash and route straight there.
 *
 * One component, both planes. The organization plane created from an inline form on the
 * list with its own organization implied; the environment plane created from this page
 * and always passed a null organization — every endpoint it made was platform-wide,
 * because it had no notion of acting on one tenant. Neither is dropped: the organization
 * now comes from the console chrome on both planes, and platform-wide coverage survives
 * as its own explicit choice, offered and honoured on the environment plane only.
 *
 * The event catalogue drifted too — seven options here and twenty-four there, in the one
 * field that decides what your system finds out about. Both planes now offer the union
 * ({@see WebhookEventCatalogue}).
 */
new #[Layout('components.layouts.console', ['title' => 'New webhook'])] class extends Component
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

    /**
     * Ask for the step-up at the door, before there is a form to lose — see
     * {@see stepUpPending()} for what it guards and why the write asks again.
     */
    public function mount(): void
    {
        $this->stepUpPending();
    }

    public string $url = '';

    /**
     * Livewire rehydrates this straight off the wire, so the keys are whatever the
     * request sent — not necessarily a gapless list. Hence the array_values() before
     * it is handed on.
     *
     * @var array<array-key, string>
     */
    public array $eventTypes = [];

    /**
     * Register an endpoint the ENVIRONMENT owns rather than one organization's.
     * Offered on the environment plane only — and refused there and then if it arrives
     * from anywhere else, because a Livewire property is client-settable and a control
     * that is merely unrendered is not a control that is enforced.
     */
    public bool $environmentWide = false;

    /**
     * Register the endpoint, reveal its secret once, and route to its detail page.
     */
    public function create(WebhookRegistry $webhooks): mixed
    {
        // A social sign-in creates the account immediately but proves nothing about the
        // address on it, and a webhook endpoint is a durable object that will be handed
        // this organization's events. Organization plane only: the gate reads the SUBJECT
        // session, and an environment administrator has no subject at all — asking it
        // there would answer "unverified" for every one of them and refuse every create.
        if (app(ConsoleScope::class)->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('create a webhook');
        }

        // Stated here in one place rather than split between attributes and this call, so
        // the two cannot drift apart. Both are public props, so a crafted wire request
        // can set either to anything.
        $this->validate([
            'url' => ['required', 'url', 'max:500'],
            'eventTypes' => ['required', 'array', 'min:1'],
            'eventTypes.*' => ['string'],
        ]);

        try {
            $organizationId = $this->targetOrganizationId();
        } catch (AuthorizationException $e) {
            // Reported on the URL field rather than thrown: on the environment plane
            // "you have not picked an organization yet" is an ordinary state of the
            // console, not a failure, and the form must survive to be resubmitted.
            $this->addError('url', $e->getMessage());

            return null;
        }

        // LAST, after authorization and after the refusals above — the same order the
        // re-key on the detail page uses. Normally a no-op, because mount() already asked.
        if ($this->stepUpPending()) {
            return null;
        }

        try {
            // Two calls rather than one with a nullable argument. The registry no longer
            // lets "every tenant's events" be expressed by a variable that happens to be
            // null, so the environment-wide case is stated at the one call site entitled
            // to make it.
            $registered = $organizationId === null
                ? $webhooks->registerForEnvironment($this->url, array_values($this->eventTypes))
                : $webhooks->register($organizationId, $this->url, array_values($this->eventTypes));
        } catch (UnsafeWebhookUrl) {
            // The registry's SSRF guard refused the target — surface it on the field
            // rather than 500. The endpoint must resolve to a public address.
            $this->addError('url', 'That URL is not allowed — it must be a public HTTPS endpoint.');

            return null;
        }

        // The plaintext secret exists only in this response; hand it to the detail page
        // as a one-time flash — it is never retrievable again.
        session()->flash('newSecret', $registered->secret);
        $this->dispatch('toast', message: 'Webhook endpoint created.');

        return $this->redirectRoute(
            app(ConsoleScope::class)->routeName('webhooks.show'),
            ['webhook' => $registered->endpoint->id],
            navigate: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'eventCatalogue' => WebhookEventCatalogue::EVENTS,
            // The one branch a page is allowed to make on the plane: whether the
            // administrator acts on several organizations or implicitly on their own.
            'mayScopeEnvironmentWide' => app(ConsoleScope::class)->plane()->choosesOrganization(),
        ];
    }

    /**
     * True when the administrator has been sent to re-enter their password first.
     *
     * Registration mints the signing secret this endpoint's receiver verifies with, and
     * hands it over in plaintext — the same credential the detail page's re-key hands
     * over, behind the same gate since the day that gate was written. Gating only the
     * re-key left the shorter path open: register a second endpoint at the address you
     * control and read its secret off the confirmation.
     */
    private function stepUpPending(): bool
    {
        $sudo = app(ConsoleStepUp::class)->challenge(
            'webhooks.create',
            'environment.webhooks.create',
            [],
            'Registering an endpoint issues a signing secret and starts sending it live events.',
        );

        if ($sudo === null) {
            return false;
        }

        $this->redirectRoute($sudo, navigate: false);

        return true;
    }

    /**
     * The organization this endpoint is registered for, or null for platform-wide.
     *
     * @throws AuthorizationException when no organization is resolved
     */
    private function targetOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        if (! $this->environmentWide) {
            // The organization comes from the scope, not from a field on this form.
            return $scope->requireOrganizationId();
        }

        // An endpoint with no organization receives EVERY organization's events in this
        // environment — members joining, sign-ins failing, roles changing, for tenants
        // that are not yours. A tenant administrator may never mint one, and "the
        // checkbox is not rendered for them" is not the guard: the property is
        // client-settable.
        abort_unless($scope->plane() === ConsolePlane::Environment, 403);

        return null;
    }
}; ?>

<div>
    <a href="{{ route($scopeRoute('webhooks')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Webhooks</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New webhook</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">The signing secret is shown once, right after you create the endpoint.</p>

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="url">Endpoint URL</label>
            <input @error('url') aria-invalid="true" aria-describedby="url-error" @enderror wire:model="url" id="url" type="url" class="input mono" placeholder="https://example.com/webhooks/cbox" autofocus>
            @error('url') <p id="url-error" class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        @if ($mayScopeEnvironmentWide)
            <div>
                <label class="flex items-start gap-2" for="environmentWide">
                    <input wire:model="environmentWide" id="environmentWide" type="checkbox" class="mt-1">
                    <span>
                        <span class="label">Environment-wide</span>
                        <span class="block text-xs" style="color:var(--faint)">Send this endpoint every organization's events in this environment, not just those of the one selected in the bar above.</span>
                    </span>
                </label>
            </div>
        @endif

        <div>
            <span class="label">Event types</span>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($eventCatalogue as $event)
                    <label wire:key="event-{{ $event }}" class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="checkbox" wire:model="eventTypes" value="{{ $event }}" class="rounded" @error('eventTypes') aria-invalid="true" aria-describedby="eventTypes-error" @enderror>
                        <span class="mono text-xs">{{ $event }}</span>
                    </label>
                @endforeach
            </div>
            @error('eventTypes') <p id="eventTypes-error" class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create webhook</button>
            <a href="{{ route($scopeRoute('webhooks')) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>

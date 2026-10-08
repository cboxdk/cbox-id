<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Webhooks\CreateWebhook;
use App\Actions\Webhooks\DeleteWebhook;
use App\Actions\Webhooks\PauseWebhook;
use App\Actions\Webhooks\ResumeWebhook;
use App\Actions\Webhooks\RotateWebhookSecret;
use App\Actions\Webhooks\UpdateWebhook;
use App\Http\Props\Console\WebhookDeliveryProps;
use App\Http\Props\Console\WebhookRowProps;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\StoreWebhookRequest;
use App\Http\Requests\Console\UpdateWebhookRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\Console\WebhookEventCatalogue;
use App\Platform\Help\HelpTopic;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Webhooks\Enums\EndpointStatus;
use Cbox\Id\Webhooks\Models\WebhookDelivery;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Cbox\Id\Webhooks\ValueObjects\RegisteredEndpoint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * CONSOLE › WEBHOOKS — the endpoints that receive a signed message after something
 * happens here, and their whole lifecycle.
 *
 * ONE CONTROLLER, BOTH PLANES. The organization plane once had a single page with an
 * inline form and a row-level Pause and nothing else: a tenant administrator could create
 * an endpoint and pause it, and could never resume it, rotate its signing secret, change
 * what it subscribed to, or delete it. The environment plane had all of that. The
 * capabilities were merged onto one component before this port, and they stay merged
 * here — the two planes differ only in which organization is implied and what the routes
 * are called.
 *
 * WHERE THE AUTHORIZATION LIVES. Under Volt every method above was reachable through one
 * POST to `/livewire/update`, so the guard had to sit inside the component — in `boot()`,
 * because only `boot()` runs on each action. Every method here is its own route with its
 * own middleware stack, and the stack runs before the controller does. What remains in
 * the controller is the part route middleware cannot answer: WHICH endpoint, and whether
 * this administrator may change THAT one.
 *
 * Every write is an ACTION (`App\Actions\Webhooks\*`): the same class the management API's
 * `/v1/webhooks` and MCP run, so an endpoint is checked, refused and RECORDED the same way
 * whichever door changed it. The edit, pause, resume, re-key and delete used to be inline
 * model writes that left no line on the trail at all. What stays here is the console's
 * own: the step-up before a secret is minted, the verified-email gate, and resolving the
 * endpoint within this page's view so a person gets the 404 or 403 page they always did.
 */
final readonly class WebhookController extends ConsoleController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $filter = $this->organizationFilter();
        $organizationId = $filter->id;

        $query = WebhookEndpoint::query()
            /*
             * An endpoint with no organization is the ENVIRONMENT's own, and it receives
             * every organization's events — so it is shipping this tenant's data
             * somewhere, and a tenant administrator has to be able to see that it exists
             * even though it is not theirs to change. Hiding it would mean a subscriber
             * to your members' sign-ins with nothing on screen saying so.
             *
             * Unfiltered — only possible for an environment administrator — this is every
             * endpoint in the environment, which is a deliberate overview and not a leak:
             * the model's environment scope still bounds it, and an organization member can
             * never reach that branch. See {@see self::organizationFilter()}; a filter
             * naming no organization here matches nothing.
             */
            ->when($filter->unknown, fn (Builder $q): Builder => $filter->apply($q))
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $scoped): Builder => $scoped
                    ->whereNull('organization_id')
                    ->orWhere('organization_id', $organizationId),
            ))
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where('url', 'like', "%{$search}%");
        }

        $endpoints = $query->paginate(25)->withQueryString();

        // The scope's own list, not a bare Organization query: on the organization plane
        // that is the member's one organization, so naming an endpoint's owner can never
        // enumerate the environment's other tenants.
        $names = $this->scope->organizationNames($endpoints->pluck('organization_id'));

        return $this->page('console/webhooks/index', 'Webhooks', [
            'endpoints' => array_map(
                fn (WebhookEndpoint $endpoint): WebhookRowProps => WebhookRowProps::from(
                    $endpoint,
                    $this->url('webhooks.show', $endpoint->id),
                    $names,
                ),
                $endpoints->items(),
            ),
            'pagination' => PaginationProps::from($endpoints),
            'search' => $search,
            /*
             * No `mayAdminister` prop, and its absence is deliberate rather than a
             * dropped feature. The page carried one and gated the "Add endpoint" button
             * on it — but the component's own `boot()` had already asserted the same
             * thing, so a non-administrator never reached the page to see the button
             * hidden. `console.admin` on the route is that assertion, and the branch it
             * fed was dead on both planes.
             */
            'organizationFilter' => $this->organizationFilterProps($filter),
            'createHref' => $this->createUrl('webhooks.create'),
            'help' => HelpProps::for(HelpTopic::Webhooks),
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        /*
         * Ask for the step-up AT THE DOOR, before there is a form to lose. The write asks
         * again — see {@see self::store()} — because a page load is not a submission and
         * the confirmation can lapse between them.
         */
        $challenge = $this->registrationChallenge();

        if ($challenge !== null) {
            return $challenge;
        }

        return $this->page('console/webhooks/create', 'New webhook', [
            'events' => WebhookEventCatalogue::offered(),
            'indexHref' => $this->url('webhooks'),
            'storeHref' => $this->url('webhooks.store'),
            /*
             * The one branch a page is allowed to make on the plane: whether the
             * administrator acts on several organizations or implicitly on their own.
             */
            'mayScopeEnvironmentWide' => $this->scope->plane()->choosesOrganization(),
            // "For which organization?" on the environment console, unless the endpoint is
            // the whole environment's; prefilled and locked when opened from an organization.
            'organization' => $this->organizationPicker(),
        ]);
    }

    public function store(StoreWebhookRequest $request): RedirectResponse
    {
        /*
         * A social sign-in creates the account immediately but proves nothing about the
         * address on it, and a webhook endpoint is a durable object that will be handed
         * this organization's events.
         *
         * ORGANIZATION PLANE ONLY: the gate reads the SUBJECT session, and an environment
         * administrator has no subject at all — asking it there would answer "unverified"
         * for every one of them and refuse every create.
         */
        if ($this->scope->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('create a webhook');
        }

        $organizationId = $this->targetOrganizationId($request, $request->boolean('environmentWide'));

        // LAST, after authorization and after the refusals above — the same order the
        // re-key uses. Normally a no-op, because create() already asked.
        $challenge = $this->registrationChallenge();

        if ($challenge !== null) {
            return $challenge;
        }

        $result = $this->act(CreateWebhook::class, [
            'url' => $request->url(),
            'event_types' => $request->eventTypes(),
            'organization_id' => $organizationId,
            'environment_wide' => $organizationId === null,
        ], ['url' => 'url', 'event_types' => 'eventTypes'], 'url');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var RegisteredEndpoint $registered */
        $registered = $result->value;

        /*
         * The plaintext secret exists only in this response. It is handed to the detail
         * page as a one-time flash and is never retrievable again — not by us either.
         */
        $this->inertia->flash('newSecret', $registered->secret);

        return to_route($this->scope->routeName('webhooks.show'), $registered->endpoint->id)
            ->with('status', 'Webhook endpoint created.');
    }

    public function show(Request $request, string $webhook): Response
    {
        $endpoint = $this->endpoint($webhook);
        $mayManage = $this->mayManage($endpoint);

        return $this->page('console/webhooks/show', $endpoint->url, [
            'endpoint' => [
                'id' => $endpoint->id,
                'url' => $endpoint->url,
                'active' => $endpoint->status === EndpointStatus::Active,
                'eventTypes' => array_values($endpoint->event_types),
                'owner' => $endpoint->organization_id !== null
                    ? (Organization::query()->whereKey($endpoint->organization_id)->value('name')
                        ?? $endpoint->organization_id)
                    : null,
            ],
            // What it already hears, kept on the form even when no longer offered, so a Save
            // never narrows a live integration by itself.
            'events' => WebhookEventCatalogue::forEndpoint(array_values($endpoint->event_types)),
            /*
             * A tenant administrator SEES the environment's own endpoint because it
             * receives their events, but may not touch it — so the controls are not
             * offered, rather than offered and refused.
             */
            'mayManage' => $mayManage,
            /*
             * Deliveries are withheld on the same rule. A platform-wide endpoint's
             * delivery log is a record of what happened in EVERY organization here, so
             * rendering it under a tenant's login would leak the other tenants' activity
             * through a page they are only meant to be able to notice.
             */
            'deliveries' => $mayManage
                ? WebhookDelivery::query()
                    ->where('endpoint_id', $endpoint->id)
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
                    ->map(WebhookDeliveryProps::from(...))
                    ->all()
                : [],
            'indexHref' => $this->url('webhooks'),
            /*
             * WHERE THIS PAGE'S WRITES GO, resolved by the server.
             *
             * The two planes call the same routes different things, and the page is one
             * file. It could ask — but then the page would be doing route-name arithmetic
             * over a plane it has no business knowing about, and getting it wrong would
             * mean a button that posts to the other plane's URL. The server knows which
             * plane it is on; it says so once.
             */
            'urls' => [
                'update' => $this->url('webhooks.update', $endpoint->id),
                'pause' => $this->url('webhooks.pause', $endpoint->id),
                'resume' => $this->url('webhooks.resume', $endpoint->id),
                'rotate' => $this->url('webhooks.rotate', $endpoint->id),
                'destroy' => $this->url('webhooks.destroy', $endpoint->id),
            ],
        ]);
    }

    public function update(UpdateWebhookRequest $request, string $webhook): RedirectResponse
    {
        $endpoint = $this->manageable($webhook);

        // The SSRF guard on the new address and which events may be KEPT — which depends
        // on the endpoint — are the action's, so the API repoints under the same rules.
        $result = $this->act(UpdateWebhook::class, [
            'id' => $endpoint->id,
            'url' => $request->url(),
            'event_types' => $request->eventTypes(),
        ], ['url' => 'url', 'event_types' => 'eventTypes'], 'url');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Subscription updated.');
    }

    public function pause(string $webhook): RedirectResponse
    {
        $endpoint = $this->manageable($webhook);

        $result = $this->act(PauseWebhook::class, ['id' => $endpoint->id]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Endpoint paused — it will stop receiving events.');
    }

    /**
     * Start a paused endpoint again.
     *
     * The organization console had no resume at all, so a tenant administrator who paused
     * an endpoint could not start it again from their own console — the one action whose
     * absence turns a reversible pause into a one-way door.
     */
    public function resume(string $webhook): RedirectResponse
    {
        $endpoint = $this->manageable($webhook);

        $result = $this->act(ResumeWebhook::class, ['id' => $endpoint->id]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Endpoint resumed.');
    }

    /**
     * Re-key the endpoint, revealing the new secret exactly once.
     *
     * The sharpest reason {@see self::endpoint()} is scoped: run against another tenant's
     * endpoint, this hands over a live signing secret, with which anything can be forged
     * into their receiver.
     *
     * And the reason it is BEHIND A STEP-UP: scoping decides WHOSE secret this hands over,
     * not whether the person at the keyboard is still the administrator. On the
     * environment plane {@see self::mayManage()} is an unconditional true, so the scope
     * refuses nothing here — a hijacked or unattended session is the whole threat, and a
     * fresh password is the only thing that answers it.
     */
    public function rotate(string $webhook): RedirectResponse
    {
        // Authorization first: a step-up in front of a 403 would hand somebody who may not
        // touch this endpoint a password prompt instead of a refusal.
        $endpoint = $this->manageable($webhook);

        $sudo = app(ConsoleStepUp::class)->challenge(
            'webhooks.show',
            'environment.webhooks.show',
            ['webhook' => $webhook],
            'Re-keying this endpoint issues a new signing secret; deliveries signed with the old one stop verifying.',
        );

        if ($sudo !== null) {
            return to_route($sudo);
        }

        $result = $this->act(RotateWebhookSecret::class, ['id' => $endpoint->id]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var RegisteredEndpoint $rotated */
        $rotated = $result->value;

        // Shown once on the next render; the sealed form is all that persists.
        $this->inertia->flash('newSecret', $rotated->secret);

        return back()->with('status', 'Signing secret rotated — update your endpoint now.');
    }

    public function destroy(string $webhook): RedirectResponse
    {
        $endpoint = $this->manageable($webhook);

        $result = $this->act(DeleteWebhook::class, ['id' => $endpoint->id]);

        return $result instanceof RedirectResponse ? $result : to_route($this->scope->routeName('webhooks'))
            ->with('status', 'Webhook endpoint deleted.');
    }

    /**
     * The endpoint, re-resolved and re-scoped on every read and write.
     *
     * The organization plane GAINED a detail page in the merge, and the environment
     * plane's lookup on the primary key alone was safe only because an environment
     * administrator — who sits above every organization here — was its sole caller.
     * Serving the same page on the organization plane with that lookup would hand a
     * tenant administrator every other tenant's endpoint by id, and with it the re-key: a
     * live signing secret for somebody else's receiver, minted on demand.
     *
     * Endpoints the environment itself owns stay VISIBLE to a tenant because they receive
     * that tenant's events — see {@see self::mayManage()} for why visible is not
     * manageable. 404 rather than 403, because the caller was not entitled to learn it
     * exists.
     */
    private function endpoint(string $id): WebhookEndpoint
    {
        $organizationId = $this->routeOrganizationId();

        $endpoint = WebhookEndpoint::query()
            ->whereKey($id)
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $scoped): Builder => $scoped
                    ->whereNull('organization_id')
                    ->orWhere('organization_id', $organizationId),
            ))
            ->first();

        abort_if($endpoint === null, 404);

        return $endpoint;
    }

    /**
     * The endpoint, refused unless this administrator may CHANGE it.
     *
     * Resolved inside the gate rather than checked afterwards, so every mutation shares
     * one refusal instead of six that have to agree.
     */
    private function manageable(string $id): WebhookEndpoint
    {
        $endpoint = $this->endpoint($id);

        abort_unless($this->mayManage($endpoint), 403);

        return $endpoint;
    }

    /**
     * Whether this administrator may change this endpoint, as opposed to look at it.
     *
     * On the ENVIRONMENT plane the administrator is the operator ABOVE every organization
     * in it, so every endpoint this console can see is theirs to manage; EnvironmentScope
     * is the real boundary and an endpoint in another environment is not visible here at
     * all. On the ORGANIZATION plane it is the member's own organization and nothing else
     * — which is what makes the environment's own platform-wide endpoint visible to a
     * tenant admin and not touchable by one.
     */
    private function mayManage(WebhookEndpoint $endpoint): bool
    {
        return $this->scope->plane() === ConsolePlane::Environment
            || $this->scope->organizationId() === $endpoint->organization_id;
    }

    /**
     * The organization an endpoint is registered for, or null for platform-wide.
     *
     * @throws AuthorizationException
     * @throws ValidationException when the form names no organization of this environment
     */
    private function targetOrganizationId(Request $request, bool $environmentWide): ?string
    {
        if (! $environmentWide) {
            // The member's own organization; on the environment console the form's "For
            // which organization?", checked against this environment.
            return $this->chosenOrganizationId($request);
        }

        /*
         * An endpoint with no organization receives EVERY organization's events in this
         * environment — members joining, sign-ins failing, roles changing, for tenants
         * that are not yours. A tenant administrator may never mint one, and "the
         * checkbox is not rendered for them" is not the guard: the field is a request
         * parameter and anybody can send it.
         */
        abort_unless($this->scope->plane() === ConsolePlane::Environment, 403);

        return null;
    }

    /**
     * The step-up in front of registration, or null when it has already been given.
     *
     * Registration mints the signing secret this endpoint's receiver verifies with, and
     * hands it over in plaintext — the same credential the re-key hands over, behind the
     * same gate since the day that gate was written. Gating only the re-key left the
     * shorter path open: register a second endpoint at an address you control and read
     * its secret off the confirmation.
     */
    private function registrationChallenge(): ?RedirectResponse
    {
        $sudo = app(ConsoleStepUp::class)->challenge(
            'webhooks.create',
            'environment.webhooks.create',
            [],
            'Registering an endpoint issues a signing secret and starts sending it live events.',
        );

        return $sudo === null ? null : to_route($sudo);
    }
}

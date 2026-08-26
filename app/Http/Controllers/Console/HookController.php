<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\StoreHookRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\Help\HelpTopic;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\ActionEndpointStatus;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › INLINE HOOKS — the customer HTTPS endpoints this platform calls
 * SYNCHRONOUSLY at a {@see HookPoint}, while somebody is waiting at a sign-in screen, and
 * whose answer changes the outcome: adding a claim to a token, or refusing the sign-in
 * outright.
 *
 * NOT WEBHOOKS, which sit one entry away in the same nav area and run after the fact.
 * The capability was called "Inline hooks" on one plane and "Event hooks" on the other,
 * which put it one word from the thing it is most dangerous to confuse it with. It gets
 * the name that says what it does.
 *
 * REGISTRATION MINTS THE SIGNING SECRET AND HANDS IT OVER ONCE. There is no rotation on
 * this resource, so creating one is not merely a way to reach the credential — it is the
 * only one, which is what makes the step-up here the whole gate rather than a formality.
 * The secret rides the flash channel to the detail page, because page props are written
 * into the browser's history entry and a live credential there is retrievable by pressing
 * Back.
 *
 * TWO SETS, KEPT APART. An endpoint the ENVIRONMENT owns fires at its hook point for
 * every organization in it — and at most points it can refuse the operation — so a tenant
 * administrator SEES those (a hook that can stop your sign-ins with nothing on screen
 * saying so is worse than one you cannot manage) and may never touch one.
 */
final readonly class HookController extends ConsoleController
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->scope->organizationId();

        $query = ExternalActionEndpoint::query()
            /*
             * The environment's own endpoints are listed beside the organization's. With
             * no organization chosen — only possible for an environment administrator —
             * this is every endpoint in the environment, which is a deliberate overview
             * rather than a leak: the model's environment scope still bounds it, and an
             * organization member can never reach that branch because their organization
             * is implicit.
             */
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $scoped): Builder => $scoped
                    ->whereNull('organization_id')
                    ->orWhere('organization_id', $organizationId),
            ))
            ->orderByDesc('id');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('url', 'like', '%'.$term.'%');
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        // The SCOPE's list, not a bare Organization query: on the organization plane that
        // is the member's one organization, so naming an endpoint's owner can never
        // enumerate the environment's other tenants.
        $owners = $this->scope->organizationNames($page->getCollection()->pluck('organization_id'));

        return $this->page('console/hooks/index', 'Inline hooks', [
            'help' => HelpProps::for(HelpTopic::InlineHooks),
            'hooks' => array_map(fn (ExternalActionEndpoint $endpoint): array => [
                'id' => $endpoint->id,
                'url' => $endpoint->url,
                'point' => $endpoint->hook_point->label(),
                'pointDescription' => $endpoint->hook_point->description(),
                'owner' => $endpoint->organization_id !== null
                    ? ($owners[$endpoint->organization_id] ?? $endpoint->organization_id)
                    : null,
                'active' => $endpoint->status === ActionEndpointStatus::Active,
                'href' => $this->url('hooks.show', $endpoint->id),
            ], $page->getCollection()->all()),
            'pagination' => PaginationProps::from($page),
            'search' => $term,
            'createHref' => $this->url('hooks.create'),
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        $this->scope->assertMayAdminister();

        // ASKED AT THE DOOR, before there is a form to lose. The write asks again — see
        // {@see self::store()} — because a window that opened while the form was being
        // filled in is not a window that was open when it was submitted.
        $sudo = $this->stepUp();

        if ($sudo !== null) {
            return to_route($sudo);
        }

        return $this->page('console/hooks/create', 'New inline hook', [
            'points' => array_map(static fn (HookPoint $point): array => [
                'value' => $point->value,
                'label' => $point->label(),
                'description' => $point->description(),
            ], HookPoint::cases()),
            /*
             * Whether registering an endpoint for the WHOLE environment is even on offer.
             * It is not an organization — it is a different kind of endpoint, firing on
             * every tenant's sign-ins and token grants — so it survives the merge as its
             * own explicit choice, offered on the environment plane alone.
             */
            'mayScopeEnvironmentWide' => $this->scope->plane()->choosesOrganization(),
            'indexHref' => $this->url('hooks'),
            'storeHref' => $this->url('hooks.store'),
        ]);
    }

    public function store(StoreHookRequest $request, ExternalActions $actions): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        /*
         * OUTBOUND REACH, handed to an account whose address nobody has confirmed. This
         * makes the platform ITSELF call a URL the creator chose, in the middle of a
         * sign-in. Internal bookkeeping is deliberately not held this way; reach outside
         * the tenant is.
         */
        if ($this->scope->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('register a hook');
        }

        if ($request->environmentWide()) {
            /*
             * An endpoint with no organization runs at this hook point for EVERY tenant in
             * the environment — and at most points it can refuse the operation — so a
             * tenant administrator minting one could stop every other tenant signing in.
             * Refused server-side: a control that is merely not rendered is not a gate.
             */
            abort_unless($this->scope->plane() === ConsolePlane::Environment, 403);

            $organizationId = null;
        } else {
            $organizationId = $this->actingOrganizationId();

            if ($organizationId === null) {
                // Reported on the field rather than thrown: on the environment plane
                // "you have not chosen an organization yet" is an ordinary state of the
                // console, and the form must survive to be resubmitted.
                return back()->withInput()->withErrors([
                    'url' => 'Choose an organization in the console header, or register the endpoint for the whole environment.',
                ]);
            }
        }

        // LAST, after authorization and after the refusals above — the order every other
        // credential in this console uses. Normally a no-op, because create() already asked.
        $sudo = $this->stepUp();

        if ($sudo !== null) {
            return to_route($sudo);
        }

        // Two calls rather than one with a nullable argument: "for every tenant here" is
        // not an organization that happens to be null, and the contract no longer lets it
        // be expressed as one.
        $registered = $organizationId === null
            ? $actions->registerForEnvironment($request->point(), $request->url())
            : $actions->register($request->point(), $request->url(), $organizationId);

        $this->inertia->flash('newSecret', $registered->secret);

        return to_route($this->scope->routeName('hooks.show'), $registered->endpoint->id)
            ->with('status', 'Inline hook endpoint registered.');
    }

    public function show(string $hook): Response
    {
        $this->scope->assertMayAdminister();

        $endpoint = $this->visible($hook);
        $mayManage = $this->mayManage($endpoint);

        $owner = $endpoint->organization_id !== null
            ? (Organization::query()->whereKey($endpoint->organization_id)->value('name') ?? $endpoint->organization_id)
            : null;

        return $this->page('console/hooks/show', 'Inline hook', [
            'hook' => [
                'id' => $endpoint->id,
                'url' => $endpoint->url,
                'point' => $endpoint->hook_point->label(),
                'pointDescription' => $endpoint->hook_point->description(),
                'owner' => is_string($owner) ? $owner : null,
                'active' => $endpoint->status === ActionEndpointStatus::Active,
            ],
            // A tenant sees the environment's own hooks because they fire on their own
            // sign-ins, and may not touch them — so the controls are not offered, rather
            // than offered and refused.
            'mayManage' => $mayManage,
            'indexHref' => $this->url('hooks'),
            'urls' => [
                'toggle' => $this->url('hooks.toggle', $endpoint->id),
                'destroy' => $this->url('hooks.destroy', $endpoint->id),
            ],
        ]);
    }

    /**
     * Pause or resume, said as one endpoint.
     *
     * The state it moves TO is read from the record rather than posted, because there are
     * exactly two of them and the record already knows which one it is in — a posted
     * intent would only add a way for the button and the row to disagree.
     */
    public function toggle(string $hook, ExternalActions $actions): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $endpoint = $this->manageable($hook);

        if ($endpoint->status === ActionEndpointStatus::Active) {
            $actions->pause($endpoint->id, $endpoint->organization_id);

            return back()->with('status', 'Endpoint paused — it will stop being called at the hook point.');
        }

        $actions->activate($endpoint->id, $endpoint->organization_id);

        return back()->with('status', 'Endpoint activated.');
    }

    public function destroy(string $hook, ExternalActions $actions): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $endpoint = $this->manageable($hook);

        $actions->remove($endpoint->id, $endpoint->organization_id);

        return to_route($this->scope->routeName('hooks'))->with('status', 'Endpoint removed.');
    }

    /**
     * The endpoint as something this administrator may SEE, or a 404.
     *
     * The organization plane GAINED a detail page in this merge, and an unscoped lookup
     * there would hand a tenant admin any other tenant's endpoint URL by id — the
     * environment plane never had to care, because its administrator sits above every
     * organization here. 404 rather than 403, because the caller was not entitled to learn
     * it exists.
     */
    private function visible(string $hook): ExternalActionEndpoint
    {
        $organizationId = $this->scope->organizationId();

        $endpoint = ExternalActionEndpoint::query()
            ->whereKey($hook)
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
     * {@see ExternalActions} answers a mismatched acting organization with a silent no-op —
     * the caller was not entitled to learn the endpoint exists — but a silent no-op reached
     * through this page would still redirect and announce "Endpoint removed" over an
     * endpoint that is still running. So the refusal is explicit here and the contract's
     * no-op stays the backstop it was written to be.
     */
    private function manageable(string $hook): ExternalActionEndpoint
    {
        $endpoint = $this->visible($hook);

        abort_unless($this->mayManage($endpoint), 403, 'This endpoint belongs to the environment. Your operator manages it.');

        return $endpoint;
    }

    /**
     * Whether this administrator may change this endpoint, as opposed to look at it.
     *
     * On the ENVIRONMENT plane the administrator sits above every organization in it, so
     * every endpoint this console can see is theirs; the environment scope is the real
     * boundary and an endpoint in another environment is not visible here at all. On the
     * ORGANIZATION plane it is the member's own organization and nothing else — which is
     * precisely what makes the environment's own hooks visible to a tenant and not
     * manageable by one.
     */
    private function mayManage(ExternalActionEndpoint $endpoint): bool
    {
        return $this->scope->plane() === ConsolePlane::Environment
            || $this->scope->organizationId() === $endpoint->organization_id;
    }

    /** The step-up route to send this administrator to, or null when the window is open. */
    private function stepUp(): ?string
    {
        return app(ConsoleStepUp::class)->challenge(
            'hooks.create',
            'environment.hooks.create',
            [],
            'Registering an inline hook issues a signing secret and puts your endpoint inside the sign-in path.',
        );
    }
}

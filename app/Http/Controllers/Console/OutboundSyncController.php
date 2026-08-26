<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\RegisterOutboundSyncRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Cbox\Id\Provisioning\Enums\ConnectionStatus;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › SYNC USERS OUT — the downstream SCIM targets this platform provisions people
 * TO. The opposite direction from "Sync users in": there, somebody else's directory is the
 * source of truth; here, this platform is, and it pushes.
 *
 * REGISTERING ONE MAKES THIS PLATFORM SEND YOUR PEOPLE SOMEWHERE. That is why it is held
 * behind the verified-address gate on the tenant plane, and why an ENVIRONMENT-WIDE
 * connection — one that receives every subject in the environment, every tenant's people,
 * to a target the creator names — is the environment plane's alone. A tenant administrator
 * may never mint one, and the checkbox not being rendered is not the guard.
 *
 * EVERY READ AND EVERY WRITE IS FENCED TO THE ACTING ORGANIZATION. The environment plane's
 * page resolved on the primary key alone, which was safe only while an environment
 * administrator was its sole caller — served to a tenant, the same code is pause, resume
 * and DELETE over any connection in the environment, including another tenant's.
 */
final readonly class OutboundSyncController extends ConsoleController
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->scope->organizationId();

        /*
         * Scoped to the acting organization when one is chosen. With none chosen — only
         * possible for an environment administrator — every connection in the environment,
         * which is a deliberate overview rather than a leak: the model's environment scope
         * still bounds it.
         *
         * It is also the only place an ENVIRONMENT-WIDE connection is listed. That is
         * deliberate: environment-wide coverage is a platform capability, so a tenant
         * administrator neither sees one here nor can reach its detail page.
         */
        $query = ProvisioningConnection::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->orderByDesc('id');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        $owners = $this->organizationNames($page->getCollection()->pluck('organization_id')->all());

        return $this->page('console/outbound-sync/index', 'Sync users out', [
            'connections' => array_map(fn (ProvisioningConnection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'baseUrl' => $connection->base_url,
                'scope' => $connection->organization_id !== null
                    ? ($owners[$connection->organization_id] ?? $connection->organization_id)
                    : null,
                'active' => $connection->status === ConnectionStatus::Active,
                // A push that has started failing is the one thing worth seeing from the
                // list: it means people are drifting out of sync somewhere downstream.
                'lastError' => $connection->last_error,
                'href' => $this->url('provisioning.show', $connection->id),
            ], $page->getCollection()->all()),
            'pagination' => PaginationProps::from($page),
            'search' => $term,
            'createHref' => $this->url('provisioning.create'),
        ]);
    }

    public function create(): Response
    {
        $this->scope->assertMayAdminister();

        return $this->page('console/outbound-sync/create', 'New outbound sync', [
            'schemes' => array_map(static fn (AuthScheme $scheme): array => [
                'value' => $scheme->value,
                'label' => $scheme === AuthScheme::Bearer ? 'Bearer token' : 'OAuth 2 client credentials',
                'hint' => $scheme === AuthScheme::Bearer
                    ? 'A long-lived token the downstream app issued you. Sent as an Authorization header on every push.'
                    : 'We fetch a short-lived token from the app before each batch. Needs a token URL and a client id.',
            ], AuthScheme::cases()),
            // Whether registering a connection for the WHOLE environment is on offer here.
            'mayScopeEnvironmentWide' => $this->scope->plane()->choosesOrganization(),
            'organizationChosen' => $this->scope->organizationId() !== null,
            'indexHref' => $this->url('provisioning'),
            'storeHref' => $this->url('provisioning.store'),
        ]);
    }

    public function store(RegisterOutboundSyncRequest $request, ProvisioningConnections $connections): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        /*
         * OUTBOUND REACH, handed to an account whose address nobody has confirmed. This
         * connection makes the platform ITSELF send your people to a URL the creator chose
         * — the same class as a webhook, which this console has always gated. Internal
         * bookkeeping (an access review, a policy) is deliberately not held this way.
         */
        if ($this->scope->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('register a provisioning connection');
        }

        if ($request->environmentWide()) {
            /*
             * An environment-wide connection receives every subject in the environment —
             * every tenant's people, over SCIM, to a target this administrator names.
             * Refused server-side: a control that is merely not rendered is not a gate.
             */
            abort_unless($this->scope->plane() === ConsolePlane::Environment, 403);

            $organizationId = null;
        } else {
            $organizationId = $this->scope->organizationId();

            if ($organizationId === null) {
                return back()->withInput()->withErrors([
                    'name' => 'Choose an organization in the console header, or register the connection for the whole environment.',
                ]);
            }
        }

        $connection = $connections->register(
            $organizationId,
            $request->name(),
            $request->baseUrl(),
            $request->scheme(),
            $request->secret(),
            authConfig: $request->authConfig(),
        )->connection;

        return to_route($this->scope->routeName('provisioning.show'), $connection->id)
            ->with('status', 'Provisioning connection registered.');
    }

    public function show(string $sync): Response
    {
        $this->scope->assertMayAdminister();

        $connection = $this->resolve($sync);

        $owner = $connection->organization_id !== null
            ? (Organization::query()->whereKey($connection->organization_id)->value('name') ?? $connection->organization_id)
            : null;

        return $this->page('console/outbound-sync/show', $connection->name, [
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'baseUrl' => $connection->base_url,
                'scheme' => $connection->auth_scheme->value,
                'scope' => is_string($owner) ? $owner : null,
                'active' => $connection->status === ConnectionStatus::Active,
                'lastError' => $connection->last_error,
            ],
            'mayAdminister' => $this->scope->mayAdminister(),
            'indexHref' => $this->url('provisioning'),
            'urls' => [
                'toggle' => $this->url('provisioning.toggle', $connection->id),
                'destroy' => $this->url('provisioning.destroy', $connection->id),
            ],
        ]);
    }

    /**
     * Pause or resume, said as one endpoint.
     *
     * The state it moves TO is read from the record: there are two of them and the record
     * knows which it is in, so a posted intent would only add a way for the button and the
     * row to disagree.
     */
    public function toggle(string $sync, ProvisioningConnections $connections): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $connection = $this->resolve($sync);

        if ($connection->status === ConnectionStatus::Active) {
            $connections->pause($connection->id);

            return back()->with('status', 'Connection paused — changes stop being pushed downstream.');
        }

        // No `resume()` on the contract: pausing is the operation it models, and coming
        // back is the absence of it. Written here rather than invented on the contract,
        // which would be a second way to say the same thing.
        $connection->status = ConnectionStatus::Active;
        $connection->save();

        return back()->with('status', 'Connection resumed — changes are pushed again from now on.');
    }

    public function destroy(string $sync): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $this->resolve($sync)->delete();

        return to_route($this->scope->routeName('provisioning'))
            ->with('status', 'Connection deleted.');
    }

    /**
     * The connection this page acts on, or a 404.
     *
     * Fenced to the acting organization, not merely to the environment. With no
     * organization chosen — only reachable by an environment administrator — the whole
     * environment resolves, which is the overview the list already shows.
     */
    private function resolve(string $sync): ProvisioningConnection
    {
        $organizationId = $this->scope->organizationId();

        $connection = ProvisioningConnection::query()
            ->whereKey($sync)
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->first();

        abort_if($connection === null, 404);

        return $connection;
    }

    /**
     * organization id => name, for the ids this page has to name.
     *
     * @param  array<array-key, mixed>  $organizationIds
     * @return array<string, string>
     */
    private function organizationNames(array $organizationIds): array
    {
        $wanted = array_values(array_unique(array_filter($organizationIds, 'is_string')));

        if ($wanted === []) {
            return [];
        }

        $names = [];

        foreach (Organization::query()->whereIn('id', $wanted)->get(['id', 'name']) as $organization) {
            $names[$organization->id] = $organization->name;
        }

        return $names;
    }
}

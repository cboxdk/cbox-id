<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\SaveClientRequest;
use App\Http\Requests\Console\StoreClientRequest;
use App\Platform\AppKind;
use App\Platform\Connect\ConnectSnippets;
use App\Platform\Connect\Snippet;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\Help\HelpTopic;
use App\Platform\ScopeCatalog;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\AccessControl\AppManifestPuller;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Response;
use Throwable;

/**
 * CONSOLE › APPS & API KEYS — every OAuth client registered here: the apps that sign
 * people in through Cbox ID and the machine credentials that call its API.
 *
 * ONE CONTROLLER, BOTH PLANES, and each plane gained half of this. An environment
 * administrator could not manage an app's manifest or sync it; an organization
 * administrator could not rotate a secret or edit an app's details, only create and
 * delete. Neither was decided — they were two pages written a year apart.
 *
 * EVERY READ AND EVERY WRITE RE-RESOLVES the app within THIS environment (the Client
 * model's own scope) AND within what the acting scope may see, then 404s otherwise. That
 * second half is the point: the environment plane resolved on the primary key alone,
 * which was safe only while an environment administrator — who holds every organization
 * here — was the sole caller. Serving the same page to a tenant administrator would hand
 * them every other tenant's app by id, and on this page that means rotating a live
 * credential out from under somebody else's production deployment, or deleting the app it
 * belongs to.
 *
 * THE SECRET IS NEVER STORED IN THE CLEAR — only its SHA-256 hash. It is shown exactly
 * once, on the flash channel, because page props are written into the browser's history
 * entry and a live credential there is retrievable by pressing Back.
 */
final readonly class ClientController extends ConsoleController
{
    private const PER_PAGE = 25;

    public function index(Request $request, ScopeCatalog $catalog): Response
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->actingOrganizationId();

        /*
         * Scoped to the acting organization when one is chosen. With none chosen — only
         * possible for an environment administrator — this is every app in the
         * environment, which is a deliberate overview rather than a leak: the model's
         * environment scope still bounds it, and an organization member can never reach
         * that branch because their organization is implicit.
         */
        $query = Client::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->orderByDesc('id');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where(fn (Builder $q): Builder => $q
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('client_id', 'like', '%'.$term.'%'));
        }

        $clients = $query->paginate(self::PER_PAGE)->withQueryString();

        /*
         * Apps the ENVIRONMENT owns rather than the organization being administered —
         * listed apart, because they are not this organization's to account for. A
         * first-party one appears in every organization's launcher and skips its consent
         * screen, so a tenant administrator has to see it or an app in their own launcher
         * looks missing here.
         *
         * `first_party` is what makes an environment-owned app a tenant's business, so
         * that is all the organization plane is shown: the environment's own back-office
         * clients are not theirs to enumerate.
         */
        $platformApps = Client::query()
            ->whereNull('organization_id')
            ->when(
                $this->scope->plane() === ConsolePlane::Organization,
                fn (Builder $q): Builder => $q->where('first_party', true),
            )
            ->orderBy('name')
            ->get();

        /*
         * How many roles each app currently declares.
         *
         * NB no `orphaned_at` filter on the CLIENT queries above: that column lives on
         * `roles`/`permissions`, which are tombstoned when an app's manifest stops
         * declaring them. `oauth_clients` has no such column — an app is deleted, not
         * orphaned — and filtering on it there was a query against a column that exists on
         * no engine.
         */
        $roleCounts = Role::query()
            ->whereIn('client_id', $clients->getCollection()->pluck('client_id')->merge($platformApps->pluck('client_id')))
            ->whereNull('orphaned_at')
            ->get(['client_id'])
            ->groupBy('client_id')
            ->map(fn ($group): int => $group->count());

        // The scope's own list, not a bare Organization query: on the organization plane
        // that is the member's one organization, so naming an app's owner can never
        // enumerate the environment's other tenants.
        $owners = $this->scope->organizationNames($clients->pluck('organization_id'));

        $showsEveryOrganization = $organizationId === null;

        return $this->page('console/clients/index', 'Apps & API keys', [
            'help' => HelpProps::for(HelpTopic::Apps),
            'clients' => $clients->getCollection()
                ->map(fn (Client $client): array => $this->row($client, $roleCounts, $owners, $showsEveryOrganization))
                ->values()
                ->all(),
            'pagination' => PaginationProps::from($clients),
            'platformApps' => $platformApps
                ->map(fn (Client $client): array => $this->row($client, $roleCounts, $owners, false))
                ->values()
                ->all(),
            'search' => $term,
            // Shown only when the whole environment is in view, where who owns a row is
            // the one thing its name does not say.
            'showsEveryOrganization' => $showsEveryOrganization,
            // The view half of the guard. A merge that rewires only the PHP renders a
            // read-only shell, so every write control on this page asks this first.
            'mayAdminister' => $this->scope->mayAdminister(),
            'createHref' => $this->url('clients.create'),
            'scopeCount' => count($catalog->keys()),
        ]);
    }

    public function create(ScopeCatalog $catalog): Response|RedirectResponse
    {
        $this->scope->assertMayAdminister();

        /*
         * Ask for the step-up AT THE DOOR, before there is a form to lose.
         *
         * The gate that enforces is the one in {@see self::store()} — this one never runs
         * for a crafted POST. This is here for the person: a password prompt raised on
         * submit sends them to another page and back to an empty form, and the form is
         * where they have just described an app.
         */
        $sudo = $this->registrationChallenge();

        if ($sudo !== null) {
            return to_route($sudo);
        }

        return $this->page('console/clients/create', 'New app', [
            'scopeGroups' => $catalog->grouped(),
            'appKinds' => array_map(
                fn (AppKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'description' => $kind->description(),
                    'needsRedirectUris' => $kind->needsRedirectUris(),
                    'confidential' => $kind->clientType() === ClientType::Confidential,
                    'defaultScopes' => $kind->defaultScopes(),
                    'flow' => $kind->flow(),
                ],
                AppKind::offered(),
            ),
            // The one branch a page is allowed to make on the plane: whether the
            // administrator acts on several organizations or implicitly on their own.
            'mayScopeEnvironmentWide' => $this->scope->plane()->choosesOrganization(),
            'indexHref' => $this->url('clients'),
            'storeHref' => $this->url('clients.store'),
        ]);
    }

    public function store(
        StoreClientRequest $request,
        ClientRegistry $clients,
        ScopeCatalog $catalog,
    ): RedirectResponse {
        $this->scope->assertMayAdminister();

        /*
         * Held on the ORGANIZATION plane only, because that is the plane the gate is
         * about: a social sign-in creates a subject immediately with an address only the
         * provider vouched for, and an app is a durable object other people will trust. An
         * environment administrator authenticates as an account member, where there is no
         * subject to read — the gate would answer "unverified" for every one of them and
         * lock the plane out of registering apps at all.
         */
        if ($this->scope->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('create an application');
        }

        $kind = $request->kind();
        $grantTypes = $kind === AppKind::Advanced ? $request->grantTypes() : $kind->grantTypes();

        if ($grantTypes === []) {
            // Reported on the checkbox that would fix it, not on a field the form does
            // not have: an error keyed to nothing renders nowhere.
            return back()->withInput()->withErrors([
                'grantAuthorizationCode' => 'Choose at least one way this app connects.',
            ]);
        }

        $redirects = $request->redirectUris();

        if (in_array('authorization_code', $grantTypes, true) && $redirects === []) {
            return back()->withInput()->withErrors([
                'redirectUris' => 'A browser-login app needs at least one redirect URI to return people to.',
            ]);
        }

        // An app the ENVIRONMENT owns is the platform's own: marked first-party it skips
        // the consent screen for EVERY organization here and appears in each of their
        // launchers. A tenant administrator may never mint one, and "the checkbox is not
        // rendered for them" is not the guard — the field is still POSTable.
        if ($request->environmentWide()) {
            abort_unless($this->scope->plane() === ConsolePlane::Environment, 403);
            $organizationId = null;
        } else {
            $organizationId = $this->scope->requireOrganizationId();
        }

        /*
         * LAST, after authorization and after every refusal above — the same order the
         * rotation uses, and for the same two reasons: a step-up in front of a 403 hands
         * somebody who may not register anything a password prompt instead of a refusal,
         * and one in front of a validation error teaches people to type it unread.
         * Normally a no-op, because `create()` already asked.
         */
        $sudo = $this->registrationChallenge();

        if ($sudo !== null) {
            return to_route($sudo);
        }

        $registered = $clients->register(new NewClient(
            name: $request->name(),
            // The kind decides where the code runs, and therefore whether it can hold a
            // secret. Only Advanced lets that be answered by hand — somebody who has told
            // us they are building a CLI has already told us it is public.
            type: $kind === AppKind::Advanced ? $request->clientType() : $kind->clientType(),
            redirectUris: $redirects,
            grantTypes: $grantTypes,
            scopes: $request->scopes($catalog),
            firstParty: $request->firstParty(),
            organizationId: $organizationId,
            postLogoutRedirectUris: $request->postLogoutRedirectUris(),
        ));

        $manifestUrl = $request->manifestUrl();

        if ($manifestUrl !== null) {
            // A published manifest URL (the pull transport) — stored on the app so the
            // scheduled sweep and "Sync now" can fetch its declared roles and permissions.
            $registered->client->forceFill(['manifest_url' => $manifestUrl])->save();
        }

        // The plaintext exists only here. On the FLASH CHANNEL, not in props: props are
        // written into the browser's history entry, where a live credential is retrievable
        // by pressing Back long after the page that showed it has gone.
        if ($registered->secret !== null && $registered->secret !== '') {
            $this->inertia->flash('revealedSecret', $registered->secret);
        }

        return to_route($this->scope->routeName('clients.show'), $registered->client->id)
            ->with('status', 'App "'.$registered->client->name.'" created.');
    }

    public function show(Request $request, string $client, ScopeCatalog $catalog): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->client($client);
        $mayManage = $this->mayManage($model);

        /*
         * THE ENVIRONMENT'S OWN ISSUER, not the deployment's URL.
         *
         * This read `config('app.url')`, which is the apex the app is installed at — so an
         * app registered in a tenant environment on its own subdomain was told its issuer
         * was `https://cboxid.com`, from a page served at `https://cbox.cboxid.com`. Every
         * developer copying that value into an SDK configured it against a host that
         * serves no discovery document, and the failure arrives one layer down as
         * "discovery request failed", pointing at the SDK rather than at the console that
         * handed them the wrong value.
         */
        $issuer = rtrim(app(IssuerResolver::class)->issuer(), '/');

        // Read back from the GRANTS rather than stored: they are what the token endpoint
        // enforces, so they cannot disagree with what this page claims.
        $kind = AppKind::forClient($model);

        $registered = array_values($model->scopes ?? []);
        $catalogKeys = $catalog->keys();

        return $this->page('console/clients/show', $model->name, [
            'client' => [
                'id' => $model->id,
                'name' => $model->name,
                'clientId' => $model->client_id,
                'confidential' => $model->type === ClientType::Confidential,
                // A private_key_jwt client is Confidential AND has no secret, deliberately:
                // a client authenticates EITHER by a shared secret OR by signing
                // assertions, never both.
                'signsAssertions' => $model->jwks !== null,
                'firstParty' => $model->first_party,
                'redirectUris' => implode("\n", $model->redirect_uris ?? []),
                'postLogoutRedirectUris' => implode("\n", $model->post_logout_redirect_uris ?? []),
                'manifestUrl' => $model->manifest_url ?? '',
                // Split by whether the catalogue knows the key, so a scope somebody typed
                // by hand survives an edit instead of being dropped the first time this
                // form is saved.
                'scopes' => array_values(array_intersect($registered, $catalogKeys)),
                'customScopes' => implode(', ', array_values(array_diff($registered, $catalogKeys))),
                'kind' => $kind->value,
                'kindLabel' => $kind->label(),
            ],
            'issuer' => $issuer,
            'discovery' => $issuer.'/.well-known/openid-configuration',
            // One per SDK that can do this app's job, filled in with its real values.
            'snippets' => array_map(
                fn (Snippet $snippet): array => [
                    'id' => $snippet->id,
                    'label' => $snippet->label,
                    'code' => $snippet->code,
                    'install' => $snippet->install,
                    'docs' => $snippet->docs,
                ],
                app(ConnectSnippets::class)->for($kind, $model, $issuer),
            ),
            'scopeGroups' => $catalog->grouped(),
            // How many roles this app currently declares. Tombstoned ones are excluded:
            // they are what the app STOPPED declaring.
            'declaredRoles' => Role::query()
                ->where('client_id', $model->client_id)
                ->whereNull('orphaned_at')
                ->count(),
            // A tenant administrator sees the platform's first-party apps because they
            // appear in their launcher, but may not touch them — so the controls are not
            // offered, rather than offered and refused.
            'mayManage' => $mayManage,
            /*
             * A STEP-UP THAT HAS JUST BEEN CLEARED, said out loud.
             *
             * Rotation sends the administrator to re-enter their password and the console
             * brings them back HERE — to a page that looks exactly as it did, with nothing
             * rotated and nothing explaining why. People concluded it was broken and did
             * the whole thing again, so the second attempt was the one that worked.
             *
             * The Volt page answered that by resuming the rotation during mount. A
             * controller must not: mount became a GET, and a GET that mints a live
             * credential is reachable by a refresh, a prefetch and the Back button. So the
             * page says the window is open and the administrator presses the button once
             * more — one click, on a request that means it.
             */
            'stepUpCleared' => $request->string('confirmed')->toString() === 'rotate',
            'indexHref' => $this->url('clients'),
            'urls' => [
                'update' => $this->url('clients.update', $model->id),
                'manifest' => $this->url('clients.manifest', $model->id),
                'sync' => $this->url('clients.sync', $model->id),
                'rotate' => $this->url('clients.rotate', $model->id),
                'destroy' => $this->url('clients.destroy', $model->id),
            ],
        ]);
    }

    public function update(SaveClientRequest $request, string $client, ScopeCatalog $catalog): RedirectResponse
    {
        $model = $this->manageable($client);

        $model->name = $request->name();
        $model->redirect_uris = $request->redirectUris();
        $model->post_logout_redirect_uris = $request->postLogoutRedirectUris();
        // THE CEILING, and narrowing it takes effect on the next token. A device or CIBA
        // request naming a scope removed here is refused outright rather than downscoped,
        // so this is a live change to what an integration can ask for — which is exactly
        // why it belongs on the page rather than behind delete-and-recreate.
        $model->scopes = $request->scopes($catalog);
        $model->save();

        return back()->with('status', 'App updated.');
    }

    public function saveManifest(Request $request, string $client, AppManifestPuller $puller): RedirectResponse
    {
        $model = $this->manageable($client);

        $request->validate(['manifestUrl' => ['nullable', 'url', 'max:500']]);

        $url = trim((string) $request->string('manifestUrl')) ?: null;

        $model->forceFill(['manifest_url' => $url])->save();

        if ($url === null) {
            return back()->with('status', 'Manifest URL cleared.');
        }

        // Pull immediately, so the app's roles appear without waiting for the sweep.
        try {
            $result = $puller->pull($model->refresh());
        } catch (Throwable $e) {
            return back()->withErrors(['manifestUrl' => 'Saved, but the sync failed: '.$e->getMessage()]);
        }

        return back()->with('status', $result !== null
            ? 'Manifest synced — '.$result->rolesDeclared.' role(s), '.$result->permissionsDeclared.' permission(s).'
            : 'Saved.');
    }

    public function sync(string $client, AppManifestPuller $puller): RedirectResponse
    {
        $model = $this->manageable($client);

        if ($model->manifest_url === null) {
            return back();
        }

        try {
            $result = $puller->pull($model);
        } catch (Throwable $e) {
            return back()->with('error', 'Sync failed: '.$e->getMessage());
        }

        return back()->with('status', $result !== null && ! $result->unchanged
            ? 'Synced — '.$result->rolesDeclared.' role(s).'
            : 'Already up to date.');
    }

    /**
     * Overlap-rotate the secret: mint a fresh one, persist only its hash, and reveal the
     * plaintext once. Public clients have no secret, so rotation is refused for them.
     *
     * BEHIND A STEP-UP. This mints a live credential for an app that already exists, and
     * on the environment plane {@see self::mayManage()} returns an unconditional true — so
     * one unattended session rotates any tenant's production app secret and puts the
     * plaintext on screen, with no re-authentication anywhere in the path.
     */
    public function rotate(string $client): RedirectResponse
    {
        // Authorization first: a step-up in front of a 403 hands somebody who may not
        // touch this app a password prompt instead of a refusal.
        $model = $this->manageable($client);

        if ($model->type !== ClientType::Confidential) {
            return back()->with('error', 'Public apps use PKCE and have no secret to rotate.');
        }

        /*
         * A private_key_jwt client is Confidential AND has no secret, by construction:
         * a client authenticates EITHER by a shared secret OR by signing assertions, never
         * both. Minting one here does not rotate anything; it ADDS a bearer credential to
         * a client that was asymmetric-only, and the authenticator then accepts client_id
         * plus secret whenever no assertion is presented. A one-click downgrade of an
         * authentication model, offered as routine hygiene.
         */
        if ($model->jwks !== null) {
            return back()->with('error', 'This app signs assertions with its own keys and has no secret. Rotate the key in its JWKS instead.');
        }

        // LAST, after authorization and after the two refusals above. Asking for a password
        // and then answering "public apps have no secret to rotate" trains people to type
        // it without reading.
        $sudo = app(ConsoleStepUp::class)->challenge(
            'clients.show',
            'environment.clients.show',
            // `confirmed` rides back as a query parameter, so the page this returns to can
            // say the window is open. See the `stepUpCleared` prop.
            ['client' => $model->id, 'confirmed' => 'rotate'],
            'Rotating this app\'s secret issues a new one and stops the old one working immediately.',
        );

        if ($sudo !== null) {
            return to_route($sudo);
        }

        $secret = 'csec_'.bin2hex(random_bytes(32));
        $model->secret_hash = hash('sha256', $secret);
        $model->save();

        $this->inertia->flash('revealedSecret', $secret);

        return back()->with('status', 'A new secret was issued — copy it now, it will not be shown again.');
    }

    /**
     * Delete the app.
     *
     * One capability that had two names — `delete` on the organization plane and
     * `deleteClient` on the environment plane — which is how a test exercising one plane
     * can pass while the other has been broken for a month.
     */
    public function destroy(string $client): RedirectResponse
    {
        $this->manageable($client)->delete();

        return to_route($this->scope->routeName('clients'))->with('status', 'App deleted.');
    }

    /**
     * One row of either list.
     *
     * @param  Collection<string, int>  $roleCounts
     * @param  array<string, string>  $owners
     * @return array<string, mixed>
     */
    private function row(Client $client, mixed $roleCounts, array $owners, bool $withOwner): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'clientId' => $client->client_id,
            'firstParty' => $client->first_party,
            'platformOwned' => $client->organization_id === null,
            'roleCount' => $roleCounts[$client->client_id] ?? 0,
            /*
             * WHAT IT IS, in one label. This read the grants directly and drew "Sign-in"
             * for authorization_code and "API" for client_credentials — so a CLI, whose
             * grant is neither, drew nothing at all and appeared in the list as an
             * unexplained "Public". Beside it sat "Confidential"/"Public", which is
             * specification vocabulary for a fact the reader of a list is not asking about.
             */
            'kindLabel' => AppKind::forClient($client)->label(),
            'owner' => $withOwner
                ? ($client->organization_id !== null
                    ? ($owners[$client->organization_id] ?? $client->organization_id)
                    : 'All organizations')
                : null,
            'href' => $this->url('clients.show', $client->id),
        ];
    }

    /**
     * The app, re-resolved and re-scoped on every read and write.
     *
     * An organization sees its own apps and the platform's first-party ones — those appear
     * in its launcher and skip its consent screen, so it must be able to read them — and
     * nothing else. 404 rather than 403, because the caller was not entitled to learn the
     * app exists.
     */
    private function client(string $id): Client
    {
        $organizationId = $this->actingOrganizationId();

        $client = Client::query()
            ->whereKey($id)
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $scoped): Builder => $scoped
                    ->where('organization_id', $organizationId)
                    ->orWhere(fn (Builder $platform): Builder => $platform
                        ->whereNull('organization_id')
                        ->where('first_party', true)),
            ))
            ->first();

        abort_if($client === null, 404);

        return $client;
    }

    /**
     * The app, refused unless this administrator may CHANGE it.
     *
     * Resolved inside the gate rather than checked afterwards, so every mutation shares one
     * answer instead of each remembering to ask.
     */
    private function manageable(string $id): Client
    {
        $this->scope->assertMayAdminister();

        $client = $this->client($id);

        abort_unless($this->mayManage($client), 403);

        return $client;
    }

    /**
     * Whether this administrator may change this app, as opposed to look at it.
     *
     * On the ENVIRONMENT plane the administrator is the operator above every organization
     * in it, so every app this console can see is theirs to manage; the environment scope
     * is the real boundary and an app in another environment is not visible here at all.
     * On the ORGANIZATION plane it is the organization's own apps and nothing else — which
     * is what makes the platform's first-party apps visible to a tenant administrator and
     * not rotatable or deletable by one.
     */
    private function mayManage(Client $client): bool
    {
        if ($this->scope->plane() === ConsolePlane::Environment) {
            return true;
        }

        return $client->organization_id !== null
            && $client->organization_id === $this->scope->organizationId();
    }

    /**
     * The step-up in front of registration, or null when the window is already open.
     *
     * Registering a confidential app mints `csec_…` and puts it on the next screen, which
     * is the same live credential rotation hands over — so it answers to the same gate.
     * Gating rotation alone only moved the cheap path: create a second app instead of
     * re-keying the first, and the plaintext arrives just the same.
     */
    private function registrationChallenge(): ?string
    {
        return app(ConsoleStepUp::class)->challenge(
            'clients.create',
            'environment.clients.create',
            [],
            'Registering an app issues a client secret, shown once on the next screen — it signs in as this app until it is rotated.',
        );
    }
}

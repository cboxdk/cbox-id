<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\PortalLinks\CreatePortalLink;
use App\Actions\Sso\ActivateSsoConnection;
use App\Actions\Sso\AddSsoDomain;
use App\Actions\Sso\CreateSsoConnection;
use App\Actions\Sso\DeleteSsoConnection;
use App\Actions\Sso\DisableSsoConnection;
use App\Actions\Sso\ImportSamlMetadata;
use App\Actions\Sso\RemoveSsoDomain;
use App\Actions\Sso\RequireSso;
use App\Actions\Sso\SetSsoDomainCapture;
use App\Actions\Sso\SsoFields;
use App\Actions\Sso\UpdateSsoConnection;
use App\Actions\Sso\VerifySsoDomain;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\SaveConnectionRequest;
use App\Http\Requests\Console\StoreConnectionRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Entitlements;
use App\Platform\Enums\PortalIntent;
use App\Platform\Help\HelpTopic;
use App\Platform\Sso\CertificateExpiryAlerts;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Throwable;

/**
 * CONSOLE › SINGLE SIGN-ON — federated SAML and OIDC connections, the email domains that
 * route people to them, and the Admin Portal link that hands the whole job to somebody
 * else's IT administrator.
 *
 * THIS WAS TWO PAGES. The organization plane had one screen that could create and activate
 * a connection, verify email domains, toggle the capture gate and mint a portal link — and
 * could not edit, disable or delete a connection at all. The environment plane had a
 * routable list → new → detail that could do all three and had none of the domain or
 * portal half. Both halves are here: the routable shape wins, because a connection URL is
 * something you send to whoever runs the identity provider, and everything the single page
 * offered comes with it.
 *
 * DOMAIN VERIFICATION STAYS ON THE LIST rather than moving to a connection's page. A
 * verified domain belongs to the ORGANIZATION, not to one connection — it is how a
 * person's email address finds whichever connection is active — so hanging it off a
 * connection would be modelling it wrong.
 */
final readonly class ConnectionController extends ConsoleController
{
    private const PER_PAGE = 25;

    public function index(Request $request, DomainVerification $domains): Response
    {
        $this->scope->assertMayAdminister();

        $filter = $this->organizationFilter();

        // The ORGANIZATION this page is about — its own on the organization console, the one
        // the URL names on an organization's SSO tab — and null on the environment-wide list.
        $organizationId = $this->routeOrganizationId();

        /*
         * Narrowed to one organization when the page is about one, or when the list is
         * filtered to one. Otherwise — only possible for an environment administrator — every
         * connection in the environment, which is a deliberate overview rather than a leak:
         * the model's environment scope still bounds it. A filter naming no organization here
         * is an empty list, never this overview ({@see \App\Platform\Console\OrganizationFilter}).
         */
        $query = $filter->apply(Connection::query())->orderByDesc('created_at');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        $connections = $query->paginate(self::PER_PAGE)->withQueryString();

        $owners = $this->scope->organizationNames($connections->pluck('organization_id'));

        return $this->page('console/connections/index', 'Enterprise SSO', [
            'help' => HelpProps::for(HelpTopic::SingleSignOn),
            'connections' => $connections->getCollection()->map(fn (Connection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'type' => strtoupper($connection->type->value),
                'active' => $connection->isActive(),
                'status' => ucfirst($connection->status->value),
                'owner' => $connection->organization_id === null
                    ? null
                    : ($owners[$connection->organization_id] ?? $connection->organization_id),
                'href' => $this->url('connections.show', $connection->id),
            ])->values()->all(),
            'pagination' => PaginationProps::from($connections),
            'search' => $term,
            /*
             * The view's own authorization question, asked through the SCOPE so it gets
             * the same answer on both planes. It used to read `CurrentUser::isAdmin()`
             * directly in ten places — a question only the organization plane can answer,
             * so on the environment plane every control silently disappeared and the page
             * rendered as an empty read-only shell.
             */
            'mayAdminister' => $this->scope->mayAdminister(),
            /*
             * The environment-wide list is about no organization, so it has no plan to be
             * entitled under and is never told it is not — an upsell there would be about a
             * decision nobody has made. Entitlement is the organization's, asked on its page.
             */
            'entitled' => $organizationId === null || $this->scope->entitled('sso'),
            'organizationFilter' => $this->organizationFilterProps($filter),
            // A domain belongs to ONE organization, so the whole-environment list has none
            // to show rather than every tenant's: they are on each organization's page.
            'domains' => $organizationId === null ? null : collect($domains->forOrganization($organizationId))
                ->map(fn (VerifiedDomain $domain): array => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                    'verified' => $domain->isVerified(),
                    'capture' => $domain->capture,
                ])->values()->all(),
            'createHref' => $this->createUrl('connections.create'),
            // The writes about ONE organization — a portal link, its domains — exist only
            // where the page is about one.
            // An organization's SAML certificates about to stop working — the daily scan's
            // warning, read live; the environment-wide list is about no one organization.
            'certificateWarnings' => $organizationId === null ? [] : array_map(fn (array $warning): array => [
                ...$warning,
                'href' => $this->url('connections.show', $warning['connection_id']),
            ], app(CertificateExpiryAlerts::class)->warningsFor($organizationId)),
            'urls' => $organizationId === null ? null : [
                'invite' => $this->url('connections.invite'),
                'addDomain' => $this->url('connections.domains.store'),
            ],
        ]);
    }

    /**
     * Mint a single-use Admin Portal link and reveal its URL once, so an administrator can
     * hand SSO setup to an external IT administrator without granting them an account.
     */
    public function invite(): RedirectResponse
    {
        $this->guardEntitled();

        // The action a management key mints a link with, recording who minted it — the
        // scope's `actorId()` here, since the environment plane has no subject session.
        $result = $this->act(CreatePortalLink::class, [
            'organization_id' => $this->scope->requireOrganizationId(),
            'intents' => [PortalIntent::Sso->value],
        ]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /*
         * ON THE FLASH CHANNEL. This link admits its holder to the tenant's SSO setup with
         * no account at all — as a page prop it would be written into the browser's
         * history entry and readable by pressing Back, which is the Inertia shape of the
         * hazard the Volt page's `protected` property was avoiding.
         */
        $this->inertia->flash('portalUrl', $result->value);

        return back();
    }

    /**
     * Register a domain for the organization this page is about and mint its DNS challenge.
     *
     * The instructions — challenge host and token — are surfaced once so the administrator
     * can publish the TXT record.
     */
    public function addDomain(Request $request, DomainVerification $domains): RedirectResponse
    {
        $this->guardEntitled();

        /*
         * NORMALIZED FIRST, THEN VALIDATED. The rule below is deliberately lower-case
         * only, so validating what was typed would refuse `ACME.com` — which is not a
         * malformed domain, it is the same domain with the shift key held. A person who
         * capitalises their own company name should not be told it is invalid.
         */
        $request->merge(['domain' => strtolower(trim((string) $request->string('domain')))]);

        $request->validate([
            // A real, dotted hostname — no scheme, no path, no '@'.
            'domain' => ['required', 'string', 'max:253', 'regex:/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
        ], [
            'domain.regex' => 'Enter a valid domain, e.g. acme.com.',
        ]);

        $result = $this->act(AddSsoDomain::class, [
            'organization_id' => $this->scope->requireOrganizationId(),
            'domain' => (string) $request->string('domain'),
        ], fallback: 'domain');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var VerifiedDomain $record */
        $record = $result->value;

        $this->inertia->flash('dns', [
            'host' => $domains->challengeHost($record->domain),
            'token' => $record->verification_token,
            'domain' => $record->domain,
        ]);

        return back();
    }

    /** Re-check the DNS TXT record for a domain this organization owns. */
    public function verifyDomain(string $domain): RedirectResponse
    {
        $this->guardEntitled();
        $this->ownedDomain($domain);

        $result = $this->act(VerifySsoDomain::class, ['id' => $domain, 'organization_id' => $this->scope->requireOrganizationId()]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return ($result->payload['verified'] ?? false) === true
            ? back()->with('status', 'Domain verified.')
            : back()->with('error', "We couldn't find the TXT record yet — DNS can take a few minutes.");
    }

    /** Toggle the capture gate on a VERIFIED domain this organization owns. */
    public function toggleCapture(string $domain): RedirectResponse
    {
        $this->guardEntitled();
        $record = $this->ownedDomain($domain);

        // Capture only makes sense once control of the domain is proven.
        abort_unless($record->isVerified(), 403);

        // The switch sends the state it moves TO; the action takes it explicitly so an API
        // retry cannot undo itself.
        $result = $this->act(SetSsoDomainCapture::class, [
            'id' => $record->id,
            'capture' => ! $record->capture,
            'organization_id' => $this->scope->requireOrganizationId(),
        ]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', $record->capture
            ? 'Capture disabled.'
            : 'Capture enabled — matching users must use SSO.');
    }

    public function removeDomain(string $domain): RedirectResponse
    {
        $this->guardEntitled();
        $this->ownedDomain($domain);

        $result = $this->act(RemoveSsoDomain::class, ['id' => $domain, 'organization_id' => $this->scope->requireOrganizationId()]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Domain removed.');
    }

    public function create(): Response
    {
        $this->scope->assertMayAdminister();

        return $this->page('console/connections/create', 'New connection', [
            // "For which organization?" — on the environment console, where the form is not
            // already about one. Prefilled and locked when it was opened from one's page.
            'organization' => $this->organizationPicker(),
            'entitled' => $this->organizationEntitled($this->prefilledOrganizationId(), 'sso'),
            /*
             * The environment plane may own a connection itself. An environment-owned one
             * signs people in and enrols them nowhere — for an environment that does not
             * use organizations, which until now could not have single sign-on at all and
             * had to invent a tenancy to get it.
             */
            'mayScopeEnvironmentWide' => $this->scope->plane() === ConsolePlane::Environment,
            'indexHref' => $this->url('connections'),
            'storeHref' => $this->url('connections.store'),
            'importHref' => $this->url('connections.import'),
        ]);
    }

    /**
     * Prefill the SAML fields from an IdP's metadata — pasted XML or a metadata URL.
     *
     * Parsed by the vetted framework importer; only the IdP fields are filled, and the
     * administrator still reviews and submits.
     */
    public function importMetadata(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $input = trim((string) $request->string('metadata'));

        if ($input === '') {
            return back()->withErrors(['metadata' => 'Paste the IdP metadata XML, or a metadata URL.']);
        }

        $result = $this->act(ImportSamlMetadata::class, ['metadata' => $input], fallback: 'metadata');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $this->inertia->flash('metadata', $result->payload);

        return back()->with('status', 'Metadata imported — review the fields and create the connection.');
    }

    public function store(StoreConnectionRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        /*
         * A tenant administrator may never mint an environment-owned connection, and "the
         * checkbox is not rendered for them" is not the guard — the field is POSTable.
         */
        if ($request->environmentWide()) {
            abort_unless($this->scope->plane() === ConsolePlane::Environment, 403);
            $organizationId = null;
        } else {
            // The form's own answer on the environment console, checked against this
            // environment and bound for the checks below; the member's own elsewhere.
            $organizationId = $this->chosenOrganizationId($request);
        }

        /*
         * Deny-by-default entitlement gate, which the environment plane never had — AND
         * ONLY WHEN THERE IS AN ORGANIZATION. An entitlement belongs to one, and
         * `entitled()` answers false when none is resolved, so asserting it
         * unconditionally would refuse the environment-owned connection this method was
         * taught to make, on the grounds that a tenant which does not exist lacks a
         * feature.
         */
        if ($organizationId !== null) {
            $this->scope->assertEntitled('sso');
        }

        if ($this->scope->plane() === ConsolePlane::Organization) {
            /*
             * The unconfirmed-address hold is a SUBJECT-plane rule: it exists because a
             * social sign-in hands us an address the provider merely passed along, and it
             * holds the durable objects that address could then be trusted for. An
             * environment administrator is an account member with no subject address in
             * this session at all, so asking the gate about them answers "unverified" for
             * everyone and would refuse every creation on that plane outright.
             */
            app(VerifiedEmailGate::class)->require('create an identity connection');
        }

        // The action discovers an OIDC issuer's endpoints — SSRF-guarded — so the connection
        // is complete: the client needs them at redirect time, and an issuer alone would
        // dead-end mid-flow.
        $result = $this->act(CreateSsoConnection::class, [
            ...$request->config(),
            'organization_id' => $organizationId,
            'environment_wide' => $organizationId === null,
            'name' => $request->name(),
            'type' => $request->connectionType()->value,
        ], self::formFields(), fallback: 'name');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Connection $connection */
        $connection = $result->value;

        return to_route($this->scope->routeName('connections.show'), $connection->id)
            ->with('status', 'Connection created as a draft.');
    }

    public function show(Request $request, string $connection, Connections $connections): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->connection($connection);

        /*
         * Certificates and signing keys are deliberately NOT prefilled — a sealed secret
         * is never returned to the browser, and leaving the field blank on save preserves
         * the stored value.
         */
        $config = $this->safeConfig($model, $connections);

        /*
         * Whether the mandate is offered, re-derived rather than trusted. It rides back on
         * the URL after an activation, and the organization's policy can have been
         * tightened from the Sign-in rules page in another tab since — offering to turn on
         * something already on would be the console lying about state it can simply read.
         */
        $passwordsStillAllowed = app(AuthPolicies::class)
            ->resolve($model->organization_id)->sso->allowsPasswordLogin();

        $justActivated = $request->string('activated')->toString() === '1';

        return $this->page('console/connections/show', $model->name, [
            'connection' => [
                'id' => $model->id,
                'name' => $model->name,
                'type' => $model->type->value,
                'typeLabel' => strtoupper($model->type->value),
                'active' => $model->isActive(),
                'status' => ucfirst($model->status->value),
                'environmentOwned' => $model->organization_id === null,
                'config' => [
                    'idp_entity_id' => self::configString($config, 'idp_entity_id'),
                    'idp_sso_url' => self::configString($config, 'idp_sso_url'),
                    'sp_entity_id' => self::configString($config, 'sp_entity_id'),
                    'sp_acs_url' => self::configString($config, 'sp_acs_url'),
                    'issuer' => self::configString($config, 'issuer'),
                    'client_id' => self::configString($config, 'client_id'),
                ],
            ],
            'organizationName' => $model->organization_id === null
                ? null
                : Organization::query()->whereKey($model->organization_id)->value('name'),
            // Only the environment console has an organization page to link to — its SSO
            // tab; on the organization plane there is exactly one organization and no page
            // about it, so the name is text rather than a link that 404s.
            'organizationHref' => $this->scope->plane() === ConsolePlane::Environment
                && $model->organization_id !== null
                    ? route('environment.organizations.sso', ['organization' => $model->organization_id])
                    : null,
            'offeringMandate' => $justActivated && $passwordsStillAllowed && $model->isActive(),
            'passwordsStillAllowed' => $passwordsStillAllowed,
            // Gated on BOTH planes, which the environment copy was not.
            'entitled' => $this->entitledFor($model),
            'indexHref' => $this->url('connections'),
            'urls' => [
                'update' => $this->url('connections.update', $model->id),
                'activate' => $this->url('connections.activate', $model->id),
                'disable' => $this->url('connections.disable', $model->id),
                'requireSso' => $this->url('connections.require-sso', $model->id),
                'destroy' => $this->url('connections.destroy', $model->id),
            ],
        ]);
    }

    /**
     * Secrets are write-once: the action carries the sealed value through for any left
     * blank, and re-discovers an OIDC issuer so its endpoints cannot drift from it.
     */
    public function update(SaveConnectionRequest $request, string $connection): RedirectResponse
    {
        $model = $this->guardEntitledConnection($connection);

        $config = [];

        foreach ($model->type === ConnectionType::Saml ? SsoFields::SAML : SsoFields::OIDC as $field) {
            $config[$field] = trim((string) $request->string($field));
        }

        $result = $this->act(UpdateSsoConnection::class, [
            ...$config,
            'id' => $model->id,
            'organization_id' => $this->routeOrganizationId(),
            'name' => $request->name(),
        ], self::formFields(), fallback: 'name');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Connection updated.');
    }

    /**
     * The form's fields are named as the action's inputs are, so a refusal about the
     * certificate or the issuer lands on that field rather than on the name.
     *
     * @return array<string, string>
     */
    private static function formFields(): array
    {
        $names = [...SsoFields::SAML, ...SsoFields::OIDC, 'name', 'type'];

        return array_combine($names, $names);
    }

    public function activate(string $connection): RedirectResponse
    {
        $model = $this->guardEntitledConnection($connection);

        // The service scopes the flip to the owning organization, so a draft cannot be
        // activated across tenants.
        $result = $this->act(ActivateSsoConnection::class, ['id' => $model->id, 'organization_id' => $this->routeOrganizationId()]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /*
         * OFFERED, NOT APPLIED, and offered HERE rather than on a settings page nobody
         * opens: the one moment somebody has demonstrably decided how their company should
         * sign in is the moment they finish connecting the identity provider.
         *
         * Tightening the mandate ends every password session in the organization, and the
         * administrator who just pressed Enable is very likely holding one of them. A side
         * effect that signs somebody out mid-task is not something to spring on them.
         */
        return to_route(
            $this->scope->routeName('connections.show'),
            ['connection' => $model->id, 'activated' => '1'],
        )->with('status', 'Connection activated.');
    }

    /**
     * Turn the mandate on for this connection's organization — through {@see RequireSso},
     * which writes the organization's EXISTING policy with the mandate raised, through the
     * contract whose decorator ends every password session there.
     */
    public function requireSso(string $connection): RedirectResponse
    {
        $model = $this->guardEntitledConnection($connection);

        // AN ORGANIZATION'S POLICY, and an environment-owned connection belongs to none.
        // The equivalent for the environment is its own sign-in rules, set on their own
        // page — writing them from here would let a control labelled "require SSO for this
        // organization" quietly change the rule for every tenant too.
        if ($model->organization_id === null) {
            return back()->with('error', 'This connection belongs to the environment, not to one organization. Set the requirement under Sign-in rules.');
        }

        $result = $this->act(RequireSso::class, ['id' => $model->id, 'organization_id' => $this->routeOrganizationId()]);

        return $result instanceof RedirectResponse ? $result : to_route($this->scope->routeName('connections.show'), $model->id)
            ->with('status', 'Single sign-on is now required.');
    }

    public function disable(string $connection): RedirectResponse
    {
        $model = $this->guardEntitledConnection($connection);

        $result = $this->act(DisableSsoConnection::class, ['id' => $model->id, 'organization_id' => $this->routeOrganizationId()]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Connection disabled.');
    }

    public function destroy(string $connection): RedirectResponse
    {
        $model = $this->guardEntitledConnection($connection);

        $result = $this->act(DeleteSsoConnection::class, ['id' => $model->id, 'organization_id' => $this->routeOrganizationId()]);

        return $result instanceof RedirectResponse ? $result : to_route($this->scope->routeName('connections'))->with('status', 'Connection deleted.');
    }

    /**
     * The connection, re-resolved and re-scoped on every read and write.
     *
     * The organization narrowing is the half the environment copy never needed. The
     * organization the page acts on is simply the boundary — and a connection the
     * ENVIRONMENT owns has none, so it is visible on the plane that holds it and nowhere else.
     */
    private function connection(string $id): Connection
    {
        $organizationId = $this->routeOrganizationId();

        $model = Connection::query()
            ->whereKey($id)
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->first();

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * The connection, refused unless its organization is entitled to SSO.
     *
     * Asked of the CONNECTION's organization rather than the page's: an entitlement
     * belongs to the organization whose sign-in this connection governs, and a connection's
     * page on the environment console is about no organization — resolving the gate against
     * that would refuse a legitimate edit while telling nobody why. On the organization
     * plane the two are the same by construction.
     */
    private function guardEntitledConnection(string $id): Connection
    {
        $this->scope->assertMayAdminister();

        $model = $this->connection($id);

        if (! $this->entitledFor($model)) {
            throw new AuthorizationException('This organization does not have access to that feature.');
        }

        return $model;
    }

    /**
     * Whether this connection's owner may use single sign-on.
     *
     * An entitlement belongs to an organization, and a connection owned by the ENVIRONMENT
     * has none — the question has no tenant to ask. It is the environment's own capability,
     * administered by whoever administers the environment, so there is nobody to withhold
     * it from and nothing to check.
     */
    private function entitledFor(Connection $model): bool
    {
        return $model->organization_id === null
            || app(Entitlements::class)->entitled($model->organization_id, 'sso');
    }

    /**
     * Deny-by-default entitlement gate for the LIST's own mutations.
     *
     * Also the guard that stops a write about no organization: `entitled()` answers false
     * when nothing is resolved rather than defaulting open.
     */
    private function guardEntitled(): void
    {
        $this->scope->assertMayAdminister();
        $this->scope->assertEntitled('sso');
    }

    /**
     * A domain the organization this page is about owns, or 404.
     *
     * The organization is a predicate IN the query, so a foreign id simply never matches
     * — the same fence `DomainVerification::forOrganization()` draws, without loading
     * every domain the organization has to find one. `requireOrganizationId()` rather
     * than the nullable reader, because a page about no organization must not thereby be
     * handed every domain in the environment by id.
     *
     * 404, not the 403 it used to be: another organization's domain is not a permission
     * this person lacks, it is a row they have no business learning exists.
     */
    private function ownedDomain(string $id): VerifiedDomain
    {
        return VerifiedDomain::query()
            ->where('organization_id', $this->scope->requireOrganizationId())
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * The decrypted config, or an empty array if the ciphertext cannot be opened (a
     * rotated key or a tampered record). A broken seal must never fatal the edit page.
     *
     * @return array<string, mixed>
     */
    private function safeConfig(Connection $model, Connections $connections): array
    {
        try {
            return $connections->config($model);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * One string field out of the decrypted config.
     *
     * The seal holds free-form JSON, so a value can be of any shape; anything that is not
     * a string is treated as absent rather than coerced into one.
     *
     * @param  array<string, mixed>  $config
     */
    private static function configString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}

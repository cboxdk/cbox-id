<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\SaveConnectionRequest;
use App\Http\Requests\Console\StoreConnectionRequest;
use App\Platform\AdminPortal;
use App\Platform\Console\ConsolePlane;
use App\Platform\Entitlements;
use App\Platform\Enums\PortalScope;
use App\Platform\Help\HelpTopic;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\DomainAlreadyClaimed;
use Cbox\Id\Federation\Exceptions\OidcDiscoveryFailed;
use Cbox\Id\Federation\Exceptions\SamlMetadataImportFailed;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Federation\OidcDiscovery;
use Cbox\Id\Federation\Saml\SamlMetadataImporter;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
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

        $organizationId = $this->actingOrganizationId();

        /*
         * Scoped to the acting organization when one is chosen. With none chosen — only
         * possible for an environment administrator — this is every connection in the
         * environment, which is a deliberate overview rather than a leak: the model's
         * environment scope still bounds it, and an organization member can never reach
         * this branch because their organization is implicit.
         */
        $query = Connection::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->orderByDesc('created_at');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        $connections = $query->paginate(self::PER_PAGE)->withQueryString();

        $owners = $this->scope->organizationNames($connections->pluck('organization_id'));

        return $this->page('console/connections/index', 'Single sign-on', [
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
             * "No organization chosen" is a real state on the environment plane and must
             * not be reported as "not entitled" — `entitled()` answers false either way,
             * and telling an administrator to contact their account team when they simply
             * have not picked an organization sends them somewhere useless.
             */
            'needsOrganization' => $organizationId === null,
            'entitled' => $this->scope->entitled('sso'),
            // A domain belongs to ONE organization, so the whole-environment overview has
            // none to show rather than every tenant's.
            'domains' => $organizationId === null ? [] : collect($domains->forOrganization($organizationId))
                ->map(fn (VerifiedDomain $domain): array => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                    'verified' => $domain->isVerified(),
                    'capture' => $domain->capture,
                ])->values()->all(),
            'createHref' => $this->url('connections.create'),
            'urls' => [
                'invite' => $this->url('connections.invite'),
                'addDomain' => $this->url('connections.domains.store'),
            ],
        ]);
    }

    /**
     * Mint a single-use Admin Portal link and reveal its URL once, so an administrator can
     * hand SSO setup to an external IT administrator without granting them an account.
     */
    public function invite(AdminPortal $portal): RedirectResponse
    {
        $this->guardEntitled();

        // `actorId()`, not the subject's: on the environment plane there is no subject
        // session to ask, and the one thing this link records is who minted it.
        $token = $portal->generate(
            $this->scope->requireOrganizationId(),
            PortalScope::Sso,
            $this->scope->actorId(),
        );

        /*
         * ON THE FLASH CHANNEL. This link admits its holder to the tenant's SSO setup with
         * no account at all — as a page prop it would be written into the browser's
         * history entry and readable by pressing Back, which is the Inertia shape of the
         * hazard the Volt page's `protected` property was avoiding.
         */
        $this->inertia->flash('portalUrl', route('portal.enter', $token));

        return back();
    }

    /**
     * Register a domain for the acting organization and mint its DNS challenge.
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

        try {
            $record = $domains->add(
                $this->scope->requireOrganizationId(),
                (string) $request->string('domain'),
            );
        } catch (DomainAlreadyClaimed) {
            return back()->withInput()->withErrors([
                'domain' => 'That domain is already claimed by another organization.',
            ]);
        }

        $this->inertia->flash('dns', [
            'host' => $domains->challengeHost($record->domain),
            'token' => $record->verification_token,
            'domain' => $record->domain,
        ]);

        return back();
    }

    /** Re-check the DNS TXT record for a domain this organization owns. */
    public function verifyDomain(string $domain, DomainVerification $domains): RedirectResponse
    {
        $this->guardEntitled();
        $this->ownedDomain($domain, $domains);

        return $domains->verify($domain)
            ? back()->with('status', 'Domain verified.')
            : back()->with('error', "We couldn't find the TXT record yet — DNS can take a few minutes.");
    }

    /** Toggle the capture gate on a VERIFIED domain this organization owns. */
    public function toggleCapture(string $domain, DomainVerification $domains): RedirectResponse
    {
        $this->guardEntitled();
        $record = $this->ownedDomain($domain, $domains);

        // Capture only makes sense once control of the domain is proven.
        abort_unless($record->isVerified(), 403);

        $domains->setCapture($domain, ! $record->capture);

        return back()->with('status', $record->capture
            ? 'Capture disabled.'
            : 'Capture enabled — matching users must use SSO.');
    }

    public function removeDomain(string $domain, DomainVerification $domains): RedirectResponse
    {
        $this->guardEntitled();
        $this->ownedDomain($domain, $domains);

        $domains->remove($domain);

        return back()->with('status', 'Domain removed.');
    }

    public function create(): Response
    {
        $this->scope->assertMayAdminister();

        return $this->page('console/connections/create', 'New connection', [
            'needsOrganization' => $this->actingOrganizationId() === null,
            'entitled' => $this->scope->entitled('sso'),
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
    public function importMetadata(Request $request, SamlMetadataImporter $importer): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $input = trim((string) $request->string('metadata'));

        if ($input === '') {
            return back()->withErrors(['metadata' => 'Paste the IdP metadata XML, or a metadata URL.']);
        }

        try {
            $metadata = str_starts_with($input, 'http://') || str_starts_with($input, 'https://')
                ? $importer->fromUrl($input)
                : $importer->fromXml($input);
        } catch (SamlMetadataImportFailed|UnsafeFederationUrl $e) {
            return back()->withErrors(['metadata' => $e->getMessage()]);
        }

        $this->inertia->flash('metadata', [
            'idp_entity_id' => $metadata->entityId,
            'idp_sso_url' => $metadata->ssoUrl,
            'idp_x509cert' => $metadata->x509cert,
        ]);

        return back()->with('status', 'Metadata imported — review the fields and create the connection.');
    }

    public function store(StoreConnectionRequest $request, Connections $connections): RedirectResponse
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
            $organizationId = $this->scope->requireOrganizationId();
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

        $type = $request->connectionType();
        $config = $request->config();

        if ($type === ConnectionType::Oidc) {
            /*
             * Resolve the provider's authorization and token endpoints from its issuer
             * (SSRF-guarded discovery) so the connection is complete — the OIDC client
             * needs them at redirect time, and an issuer alone would dead-end mid-flow.
             */
            try {
                $config = array_merge($config, app(OidcDiscovery::class)
                    ->fromIssuer((string) $request->string('issuer'))
                    ->toConfig());
            } catch (OidcDiscoveryFailed|UnsafeFederationUrl $e) {
                return back()->withInput()->withErrors([
                    'issuer' => "Couldn't read the provider's OpenID configuration — check the issuer URL. ({$e->getMessage()})",
                ]);
            }
        }

        $connection = $connections->create($organizationId, $type, $request->name(), $config);

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
            // Only the environment console has an organization detail page to link to; on
            // the organization plane there is exactly one organization and no page about
            // it, so the name is text rather than a link that 404s.
            'organizationHref' => $this->scope->plane() === ConsolePlane::Environment
                && $model->organization_id !== null
                    ? route('environment.organizations.show', $model->organization_id)
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

    public function update(
        SaveConnectionRequest $request,
        string $connection,
        Connections $connections,
        SecretBox $secretBox,
    ): RedirectResponse {
        $model = $this->guardEntitledConnection($connection);

        // Start from the sealed config so any secret left blank is carried through.
        $current = $this->safeConfig($model, $connections);

        if ($model->type === ConnectionType::Saml) {
            $cert = $request->secretOr('idp_x509cert', self::configString($current, 'idp_x509cert'));

            if ($cert === '') {
                return back()->withInput()->withErrors(['idp_x509cert' => 'A signing certificate is required.']);
            }

            $config = [
                'idp_entity_id' => trim((string) $request->string('idp_entity_id')),
                'idp_sso_url' => trim((string) $request->string('idp_sso_url')),
                'idp_x509cert' => $cert,
                'sp_entity_id' => trim((string) $request->string('sp_entity_id')),
                'sp_acs_url' => trim((string) $request->string('sp_acs_url')),
            ];
        } else {
            $key = $request->secretOr('signing_key', self::configString($current, 'signing_key'));

            if ($key === '') {
                return back()->withInput()->withErrors(['signing_key' => 'A signing key is required.']);
            }

            // Secrets are write-once: a blank field keeps the sealed value.
            $secret = $request->secretOr('client_secret', self::configString($current, 'client_secret'));

            if ($secret === '') {
                return back()->withInput()->withErrors(['client_secret' => 'A client secret is required.']);
            }

            $config = [
                'issuer' => trim((string) $request->string('issuer')),
                'client_id' => trim((string) $request->string('client_id')),
                'client_secret' => $secret,
                'signing_key' => $key,
            ];

            try {
                $config = array_merge($config, app(OidcDiscovery::class)
                    ->fromIssuer((string) $request->string('issuer'))
                    ->toConfig());
            } catch (OidcDiscoveryFailed|UnsafeFederationUrl $e) {
                return back()->withInput()->withErrors([
                    'issuer' => "Couldn't read the provider's OpenID configuration — check the issuer URL. ({$e->getMessage()})",
                ]);
            }
        }

        $model->name = $request->name();
        $model->config_encrypted = $secretBox->seal(
            json_encode($config, JSON_THROW_ON_ERROR),
            $model->secretContext(),
        );
        $model->save();

        return back()->with('status', 'Connection updated.');
    }

    public function activate(string $connection, Connections $connections): RedirectResponse
    {
        $model = $this->guardEntitledConnection($connection);

        // The service scopes the flip to the owning organization, so a draft cannot be
        // activated across tenants.
        $connections->activate($model->organization_id, $model->id);

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
     * Turn the mandate on for this connection's organization.
     *
     * Written through the {@see AuthPolicies} contract, which is what makes the session
     * revocation happen: it lives in a decorator around this interface, so reaching for
     * the concrete store would refuse tomorrow's password logins and leave every session
     * that was opened with one wide open.
     *
     * The new override is the organization's EXISTING policy with the mandate raised — its
     * own override if it has one, otherwise the environment baseline. Starting from a bare
     * {@see AuthPolicy} would write this organization's first override out of the value
     * object's defaults, quietly restating a 12-character minimum for a tenant whose
     * environment asked for 16.
     */
    public function requireSso(string $connection, AuthPolicies $policies): RedirectResponse
    {
        $model = $this->guardEntitledConnection($connection);

        // AN ORGANIZATION'S POLICY, and an environment-owned connection belongs to none.
        // The equivalent for the environment is its own sign-in rules, set on their own
        // page — writing them from here would let a control labelled "require SSO for this
        // organization" quietly change the rule for every tenant too.
        if ($model->organization_id === null) {
            return back()->with('error', 'This connection belongs to the environment, not to one organization. Set the requirement under Sign-in rules.');
        }

        $current = $policies->overrideFor($model->organization_id) ?? $policies->forEnvironment();

        $policies->setForOrganization($model->organization_id, new AuthPolicy(
            minLength: $current->minLength,
            requireBreachCheck: $current->requireBreachCheck,
            maxAgeDays: $current->maxAgeDays,
            reuseHistory: $current->reuseHistory,
            mfa: $current->mfa,
            sso: SsoEnforcement::Required,
            lockoutThreshold: $current->lockoutThreshold,
        ));

        return to_route($this->scope->routeName('connections.show'), $model->id)
            ->with('status', 'Single sign-on is now required.');
    }

    public function disable(string $connection): RedirectResponse
    {
        // No service method disables a connection; the status flips on the scoped model
        // directly (mirrors how the organization console persists status changes).
        $model = $this->guardEntitledConnection($connection);
        $model->status = ConnectionStatus::Inactive;
        $model->save();

        return back()->with('status', 'Connection disabled.');
    }

    public function destroy(string $connection): RedirectResponse
    {
        // No service delete exists; the scoped model is removed directly.
        $this->guardEntitledConnection($connection)->delete();

        return to_route($this->scope->routeName('connections'))->with('status', 'Connection deleted.');
    }

    /**
     * The connection, re-resolved and re-scoped on every read and write.
     *
     * The organization narrowing is the half the environment copy never needed. The acting
     * organization is simply the boundary — and a connection the ENVIRONMENT owns has none,
     * so it is visible on the plane that holds it and nowhere else.
     */
    private function connection(string $id): Connection
    {
        $organizationId = $this->actingOrganizationId();

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
     * Asked of the CONNECTION's organization rather than the acting one: an entitlement
     * belongs to the organization whose sign-in this connection governs, and an
     * environment administrator following a deep link has not necessarily chosen one —
     * resolving the gate against a blank selection would refuse a legitimate edit while
     * telling nobody why. On the organization plane the two are the same by construction.
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
     * Also the guard that stops a write with no organization chosen: `entitled()` answers
     * false when nothing is resolved rather than defaulting open.
     */
    private function guardEntitled(): void
    {
        $this->scope->assertMayAdminister();
        $this->scope->assertEntitled('sso');
    }

    /**
     * A domain the ACTING organization owns, or refuse.
     *
     * The contract is already organization-scoped, but resolving THROUGH it means a
     * foreign id simply never matches. `requireOrganizationId()` rather than the nullable
     * reader, because an environment administrator who has chosen nothing must not
     * thereby be handed every domain in the environment by id.
     */
    private function ownedDomain(string $id, DomainVerification $domains): VerifiedDomain
    {
        foreach ($domains->forOrganization($this->scope->requireOrganizationId()) as $domain) {
            if ($domain->id === $id) {
                return $domain;
            }
        }

        abort(403);
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

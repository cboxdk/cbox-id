<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Sso\ActivateSsoConnection;
use App\Actions\Sso\CreateSsoConnection;
use App\Actions\Sso\ImportSamlMetadata;
use App\Actions\Sso\SsoFields;
use App\Actions\Sso\UpdateSsoConnection;
use App\Platform\Enums\PortalIntent;
use App\Platform\Portal\PortalGuides;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * SINGLE SIGN-ON IN THE ADMIN PORTAL — a guided setup in the order an identity provider
 * actually demands it:
 *
 *  1. pick the identity provider, and get its guide ({@see PortalGuides});
 *  2. START the connection — a draft with only our half, so the ACS URL and entity id (or
 *     the OIDC redirect URI) exist to be copied, field by field, into the identity
 *     provider's own setup screen;
 *  3. bring back what the identity provider gives: its metadata URL or file, or an issuer
 *     and client credentials;
 *  4. prove the domain that routes people to it;
 *  5. ACTIVATE.
 *
 * Each step is the console's own SSO action run as the portal session — which also means
 * activation refuses a connection still missing the identity provider's half, whoever asks.
 * Nothing here takes an organization from the request.
 */
final readonly class PortalSsoController extends PortalController
{
    public function show(Request $request, DomainVerification $domains): Response
    {
        $this->requireIntent(PortalIntent::Sso);

        $organizationId = $this->organizationId();
        $chosen = $request->string('provider')->toString();

        $connections = Connection::query()
            ->where('organization_id', $organizationId)
            ->whereNull('provider')
            ->whereIn('type', [ConnectionType::Saml, ConnectionType::Oidc])
            ->orderByDesc('created_at')
            ->get();

        return $this->portalPage('portal/sso', __('portal.sso.title'), [
            'guides' => PortalGuides::sso(),
            'provider' => in_array($chosen, PortalGuides::ssoKeys(), true) ? $chosen : null,
            'connections' => $connections->map(static fn (Connection $connection): array => self::row($connection))->values()->all(),
            'domains' => PortalDomainController::rows($domains, $organizationId),
            'urls' => [
                'self' => route('portal.sso'),
                'start' => route('portal.connections.store'),
                'addDomain' => route('portal.domains.store'),
            ],
        ]);
    }

    /** Step 2: the draft, with only our half — so our half exists to be copied. */
    public function store(Request $request): RedirectResponse
    {
        $this->requireIntent(PortalIntent::Sso);

        $guide = collect(PortalGuides::sso())->firstWhere('key', $request->string('provider')->toString());

        abort_if($guide === null, 422);

        $name = trim($request->string('name')->toString());

        $result = $this->act(CreateSsoConnection::class, [
            'organization_id' => $this->organizationId(),
            'name' => $name === '' ? $guide['name'] : $name,
            'type' => $guide['protocol'],
            'pending_idp' => true,
        ], ['name' => 'name'], 'name');

        return $result instanceof RedirectResponse
            ? $result
            : redirect()->route('portal.sso', ['provider' => $guide['key']])
                ->with('status', __('portal.sso.started'));
    }

    /** Step 3, by hand: the identity provider's values, typed or pasted field by field. */
    public function update(Request $request, string $connection): RedirectResponse
    {
        $this->requireIntent(PortalIntent::Sso);

        $keys = ['idp_entity_id', 'idp_sso_url', 'idp_x509cert', 'issuer', 'client_id', 'client_secret', 'signing_key'];
        $input = ['id' => $connection, 'organization_id' => $this->organizationId()];

        foreach ($keys as $key) {
            $value = trim($request->string($key)->toString());

            if ($value !== '') {
                $input[$key] = $value;
            }
        }

        $result = $this->act(UpdateSsoConnection::class, $input, array_combine($keys, $keys), 'idp');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('portal.sso.saved'));
    }

    /**
     * Step 3, from metadata: the identity provider's metadata URL or XML read for its
     * entity id, sign-on URL and certificate, and saved onto the connection — two actions,
     * the same two the console's "Import metadata" and "Save" are.
     */
    public function metadata(Request $request, string $connection): RedirectResponse
    {
        $this->requireIntent(PortalIntent::Sso);

        $request->validate(['metadata' => ['required', 'string', 'max:500000']]);

        $imported = $this->act(ImportSamlMetadata::class, ['metadata' => $request->string('metadata')->toString()], ['metadata' => 'metadata'], 'metadata');

        if ($imported instanceof RedirectResponse) {
            return $imported;
        }

        /** @var array<string, string> $fields */
        $fields = $imported->payload ?? [];

        $result = $this->act(UpdateSsoConnection::class, [
            'id' => $connection,
            'organization_id' => $this->organizationId(),
            ...array_intersect_key($fields, array_flip(['idp_entity_id', 'idp_sso_url', 'idp_x509cert'])),
        ], [], 'metadata');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('portal.sso.metadata_imported'));
    }

    /** Step 5. */
    public function activate(string $connection): RedirectResponse
    {
        $this->requireIntent(PortalIntent::Sso);

        $result = $this->act(ActivateSsoConnection::class, [
            'id' => $connection,
            'organization_id' => $this->organizationId(),
        ], [], 'activate');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('portal.sso.activated'));
    }

    /**
     * A connection as the page draws it: its state, OUR values to paste (with OneLogin's
     * validator spelled out, and the SP metadata URL that carries them all), and the
     * identity provider's non-secret values once known.
     *
     * @return array<string, mixed>
     */
    private static function row(Connection $connection): array
    {
        $config = SsoFields::config($connection);
        $ours = SsoFields::serviceProvider($connection);
        $acs = $config['sp_acs_url'] ?? ($ours['sp_acs_url'] ?? null);

        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'protocol' => $connection->type->value,
            'status' => $connection->status->value,
            'active' => $connection->isActive(),
            'complete' => SsoFields::isComplete($connection->type, $config),
            'values' => array_filter([
                'acs_url' => is_string($acs) ? $acs : null,
                'acs_regex' => is_string($acs) ? '^'.preg_quote($acs, '/').'$' : null,
                'entity_id' => is_string($config['sp_entity_id'] ?? null) ? $config['sp_entity_id'] : ($ours['sp_entity_id'] ?? null),
                'redirect_uri' => $ours['redirect_uri'] ?? null,
                // Our half as one document, for an identity provider that imports it.
                'metadata_url' => $ours['sp_metadata_url'] ?? null,
            ], static fn (?string $value): bool => $value !== null),
            'idp' => array_filter([
                'idp_entity_id' => $config['idp_entity_id'] ?? null,
                'idp_sso_url' => $config['idp_sso_url'] ?? null,
                'issuer' => $config['issuer'] ?? null,
                'client_id' => $config['client_id'] ?? null,
            ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
            'urls' => [
                'update' => route('portal.connections.update', $connection->id),
                'metadata' => route('portal.connections.metadata', $connection->id),
                'activate' => route('portal.connections.activate', $connection->id),
            ],
        ];
    }
}

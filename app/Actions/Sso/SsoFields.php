<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\OidcDiscoveryFailed;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Federation\OidcDiscovery;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Enterprise SSO connections and their email domains, as the management API takes and
 * returns them — shared by every SSO action, and not one itself.
 *
 * THE SECRETS ARE WRITE-ONLY. A SAML connection's IdP certificate, an OIDC connection's
 * client secret and signing key are sealed into the connection and never returned by any
 * action; a change that leaves one out keeps the one on file. What IS returned is what an
 * administrator copies into the identity provider or reads off it: entity ids, URLs, the
 * issuer and the client id.
 */
final class SsoFields
{
    /** The config keys of each type, in the order the console's form asks for them. */
    public const array SAML = ['idp_entity_id', 'idp_sso_url', 'idp_x509cert', 'sp_entity_id', 'sp_acs_url'];

    public const array OIDC = ['issuer', 'client_id', 'client_secret', 'signing_key'];

    /** Never returned, never on the trail. */
    public const array SECRETS = ['idp_x509cert', 'client_secret', 'signing_key'];

    /** The URLs among them, refused when they are not one. */
    private const array URLS = ['idp_sso_url', 'sp_acs_url', 'issuer'];

    /** The SAML keys this environment knows for itself — derived from the connection when left blank. */
    public const array SERVICE_PROVIDER = ['sp_entity_id', 'sp_acs_url'];

    /**
     * The config inputs of both types. Which set applies is the connection's type.
     *
     * @return list<Field>
     */
    public static function configFields(): array
    {
        return [
            Field::string('idp_entity_id')->nullable()->max(500)->describe('SAML: the identity provider\'s entity id.'),
            Field::string('idp_sso_url')->nullable()->max(500)->format('uri')->describe('SAML: the identity provider\'s single sign-on URL.'),
            Field::string('idp_x509cert')->nullable()->max(20000)->describe('SAML: the identity provider\'s signing certificate (PEM). Write-only.'),
            Field::string('sp_entity_id')->nullable()->max(500)->describe('SAML: the entity id this environment presents as the service provider. Left out on create, this connection\'s own.'),
            Field::string('sp_acs_url')->nullable()->max(500)->format('uri')->describe('SAML: where the identity provider posts its assertions. Left out on create, this connection\'s own ACS URL.'),
            Field::string('issuer')->nullable()->max(500)->format('uri')->describe('OIDC: the provider\'s issuer URL; its endpoints are discovered from it.'),
            Field::string('client_id')->nullable()->max(500)->describe('OIDC: the client id registered at the provider.'),
            Field::string('client_secret')->nullable()->max(500)->describe('OIDC: the client secret. Write-only.'),
            Field::string('signing_key')->nullable()->max(20000)->describe('OIDC: the signing key. Write-only.'),
        ];
    }

    /**
     * The config of a $type connection out of the input, every key of that type present
     * (blank when not sent) and trimmed.
     *
     * @return array<string, string>
     */
    public static function configFrom(ActionContext $context, ConnectionType $type): array
    {
        $config = [];

        foreach ($type === ConnectionType::Saml ? self::SAML : self::OIDC as $key) {
            $config[$key] = trim($context->string($key));
        }

        return $config;
    }

    /**
     * What this environment presents to the identity provider for $connection — the values
     * an administrator pastes into their IdP's setup screen: for SAML the entity id and the
     * ACS URL, for OIDC the redirect URI. Keyed by the connection's id, because the route
     * that receives the assertion is.
     *
     * @return array<string, string>
     */
    public static function serviceProvider(Connection $connection): array
    {
        return $connection->type === ConnectionType::Saml
            ? [
                'sp_entity_id' => url('/sso/saml/'.$connection->id),
                'sp_acs_url' => route('sso.saml.acs', $connection->id),
            ]
            : ['redirect_uri' => route('sso.oidc.callback', $connection->id)];
    }

    /**
     * Whether every value the connection's type requires is on file — what activation asks
     * before anybody is routed to it.
     *
     * @param  array<string, mixed>  $config
     */
    public static function isComplete(ConnectionType $type, array $config): bool
    {
        foreach ($type === ConnectionType::Saml ? self::SAML : self::OIDC as $key) {
            $value = $config[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Refuse a config with a blank required value or a URL that is not one — every problem
     * at once, so nobody fixes the first and is refused for the second.
     *
     * @param  array<string, string>  $config
     *
     * @throws ActionRefused
     */
    public static function assertComplete(array $config): void
    {
        $problems = [];

        foreach ($config as $key => $value) {
            if ($value === '') {
                $problems[$key] = self::label($key).' is required.';
            } elseif (in_array($key, self::URLS, true) && ! self::isUrl($value)) {
                $problems[$key] = self::label($key).' must be an absolute http(s) URL.';
            }
        }

        if ($problems !== []) {
            throw ActionRefused::onFields('incomplete_connection', $problems);
        }
    }

    /**
     * An OIDC config with the provider's endpoints discovered from its issuer — SSRF-guarded
     * — so the connection is complete: the client needs them at redirect time, and an issuer
     * alone would dead-end mid-flow.
     *
     * @param  array<string, string>  $config
     * @return array<string, mixed>
     *
     * @throws ActionRefused
     */
    public static function discovered(array $config): array
    {
        try {
            return array_merge($config, app(OidcDiscovery::class)->fromIssuer($config['issuer'] ?? '')->toConfig());
        } catch (OidcDiscoveryFailed|UnsafeFederationUrl $e) {
            throw ActionRefused::because('discovery_failed', "Couldn't read the provider's OpenID configuration — check the issuer URL. ({$e->getMessage()})", 'issuer');
        }
    }

    /**
     * The decrypted config, or nothing when the seal cannot be opened (a rotated key, a
     * tampered row) — a broken seal must not fail a read.
     *
     * @return array<string, mixed>
     */
    public static function config(Connection $connection): array
    {
        try {
            return app(Connections::class)->config($connection);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The connection this principal may reach, or a 404: the organization it narrowed to
     * (an organization administrator always narrows to their own), else the environment.
     *
     * @throws ActionRefused
     */
    public static function connection(ActionContext $context): Connection
    {
        $organizationId = EnterpriseReach::narrowedTo($context);

        return Connection::query()
            ->whereKey($context->string('id'))
            ->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->first() ?? throw ActionRefused::notFound('connection');
    }

    /**
     * The connection, refused unless its organization is entitled to SSO — asked of the
     * CONNECTION's organization: the one whose sign-in it governs.
     *
     * @throws ActionRefused
     */
    public static function changeable(ActionContext $context): Connection
    {
        $connection = self::connection($context);

        EnterpriseReach::assertEntitled($connection->organization_id, 'sso');

        return $connection;
    }

    /**
     * A domain this principal may reach, or a 404.
     *
     * @throws ActionRefused
     */
    public static function domain(ActionContext $context): VerifiedDomain
    {
        $organizationId = EnterpriseReach::narrowedTo($context);

        $domain = VerifiedDomain::query()
            ->whereKey($context->string('id'))
            ->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->first() ?? throw ActionRefused::notFound('domain');

        EnterpriseReach::assertEntitled($domain->organization_id, 'sso');

        return $domain;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Connection $connection): array
    {
        $config = self::config($connection);
        $public = [];

        foreach (['idp_entity_id', 'idp_sso_url', 'sp_entity_id', 'sp_acs_url', 'issuer', 'client_id'] as $key) {
            $value = $config[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $public[$key] = $value;
            }
        }

        $configurable = in_array($connection->type, [ConnectionType::Saml, ConnectionType::Oidc], true);

        return [
            'id' => $connection->id,
            'organization_id' => $connection->organization_id,
            'name' => $connection->name,
            'type' => $connection->type->value,
            'provider' => $connection->provider,
            'status' => $connection->status->value,
            'active' => $connection->isActive(),
            // A draft created before its identity provider's details were known is not.
            'complete' => ! $configurable || self::isComplete($connection->type, $config),
            'config' => $public === [] ? (object) [] : $public,
            // What to paste into the identity provider; null for a social sign-in connection.
            'service_provider' => $configurable ? self::serviceProvider($connection) : null,
            'created_at' => Timestamp::of($connection->getAttribute('created_at')),
            'updated_at' => Timestamp::of($connection->getAttribute('updated_at')),
        ];
    }

    /**
     * A domain, with the DNS record that proves it while it is still unproven — the record
     * is published in public DNS, so it is not a secret, and an agent that lost the create
     * answer must still be able to finish.
     *
     * @return array<string, mixed>
     */
    public static function presentDomain(VerifiedDomain $domain): array
    {
        return [
            'id' => $domain->id,
            'organization_id' => $domain->organization_id,
            'domain' => $domain->domain,
            'verified' => $domain->isVerified(),
            'verified_at' => Timestamp::of($domain->verified_at),
            'capture' => (bool) $domain->capture,
            'verification' => $domain->isVerified() ? null : [
                'type' => 'TXT',
                'name' => app(DomainVerification::class)->challengeHost($domain->domain),
                'value' => $domain->verification_token,
            ],
        ];
    }

    private static function label(string $key): string
    {
        return match ($key) {
            'idp_entity_id' => 'The IdP entity id',
            'idp_sso_url' => 'The IdP single sign-on URL',
            'idp_x509cert' => 'A signing certificate',
            'sp_entity_id' => 'The SP entity id',
            'sp_acs_url' => 'The ACS URL',
            'issuer' => 'The issuer URL',
            'client_id' => 'The client id',
            'client_secret' => 'A client secret',
            'signing_key' => 'A signing key',
            default => $key,
        };
    }

    private static function isUrl(string $value): bool
    {
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return filter_var($value, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true);
    }
}

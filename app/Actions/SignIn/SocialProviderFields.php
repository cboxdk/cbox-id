<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\SignInProviders;
use Cbox\Id\Federation\Enums\ClientSecretKind;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Enums\ProviderCapability;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\OidcDiscovery;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\ValueObjects\ProviderTemplate;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Social sign-in providers as the management API takes and returns them. A helper, not an
 * action.
 *
 * WHAT IS NEVER RETURNED is the connection's configuration: the client secret, and Apple's
 * private key, go in and stay in. A provider is described by what it is and where its
 * callback is, which is everything a caller needs to finish setting it up at the provider.
 *
 * TWO OWNERS. A provider belongs to the ENVIRONMENT — offered on every sign-in page in it,
 * which is what "turn on Google for my app" means — or to one ORGANIZATION, which then
 * replaces the environment's for that provider on its own page. The precedence is the
 * framework's ({@see SignInProviders}); `level` says which
 * a row is.
 */
final class SocialProviderFields
{
    /**
     * A catalogue entry that can actually be used to sign somebody in, or null.
     *
     * Being IN the catalogue is not the same question as being usable here: an entry can
     * carry a directory and no sign-in half, and a connection would then be built out of
     * endpoints it does not have. Deny-by-default, in the one place the key crosses in.
     */
    public static function loginTemplate(string $key): ?ProviderTemplate
    {
        if ($key === '') {
            return null;
        }

        $template = ProviderCatalog::find($key);

        return $template?->supports(ProviderCapability::Login) === true ? $template : null;
    }

    /**
     * The provider-specific values, as one object whose properties are every parameter any
     * sign-in provider in the catalogue asks for — so the schema names them all and a value
     * no provider takes is refused rather than carried into a connection.
     */
    public static function parametersField(): Field
    {
        $properties = [];

        foreach (ProviderCatalog::withCapability(ProviderCapability::Login) as $template) {
            foreach ($template->parameters as $parameter) {
                $properties[$parameter->key] ??= Field::string($parameter->key)->nullable()->max(5000)->describe($parameter->label.' ('.$template->name.').');
            }
        }

        ksort($properties);

        return Field::object('parameters', array_values($properties))
            ->describe('What the chosen provider needs besides its client credentials — Microsoft\'s directory, Okta\'s domain, Apple\'s team id, key id and private key.');
    }

    /** The OIDC scopes every sign-in asks for — what a connection with no list of its own requests. */
    public const array OIDC_SCOPES = ['openid', 'email', 'profile'];

    /**
     * Extra scopes, as the API takes them. A scope is an RFC 6749 §3.3 scope-token: printable
     * ASCII without spaces, quotes or backslashes, so a value cannot smuggle a second
     * parameter into the authorization request.
     */
    public static function scopesField(): Field
    {
        return Field::list('scopes', Field::string('scope')->max(200))
            ->max(20)
            ->describe('Extra scopes to request beyond the ones sign-in needs (for example `read:org` on GitHub). Replaces any extra scopes already set; an empty list removes them.');
    }

    /**
     * The extra scopes sent, cleaned and checked.
     *
     * @return list<string>
     *
     * @throws ActionRefused
     */
    public static function scopes(ActionContext $context): array
    {
        $scopes = [];

        foreach ($context->array('scopes') as $scope) {
            $scope = is_string($scope) ? trim($scope) : '';

            if ($scope === '') {
                continue;
            }

            if (preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/', $scope) !== 1) {
                throw ActionRefused::because('invalid_scope', '"'.$scope.'" is not a scope: no spaces, quotes or backslashes.', 'scopes');
            }

            $scopes[] = $scope;
        }

        return array_values(array_unique($scopes));
    }

    /**
     * The scope keys a provider's config carries for a list of extra scopes. OAuth 2.0
     * requests its extras on top of the catalogue's; OIDC stores its whole list, so the
     * extras go on top of the three every sign-in asks for. `extra_scopes` is kept beside
     * either, so the list can be read back as the administrator wrote it.
     *
     * @param  list<string>  $extra
     * @return array<string, list<string>>
     */
    public static function scopeConfig(ProviderTemplate $template, array $extra): array
    {
        if ($extra === []) {
            return ['extra_scopes' => [], 'scopes' => []];
        }

        return [
            'extra_scopes' => $extra,
            'scopes' => $template->isOidc() ? array_values(array_unique([...self::OIDC_SCOPES, ...$extra])) : $extra,
        ];
    }

    /**
     * The provider-specific values a template asks for, from the request, with every
     * missing one reported at once — reporting the first and stopping would walk somebody
     * through Apple's three fields one round-trip at a time.
     *
     * @param  array<string, string>  $current  values already on file, kept for any not sent
     * @return array<string, string>
     *
     * @throws ActionRefused
     */
    public static function parameters(ActionContext $context, ProviderTemplate $template, array $current = []): array
    {
        $given = $context->array('parameters');
        $values = [];
        $missing = [];

        foreach ($template->parameters as $parameter) {
            // Blank means "keep what is on file" when something is — the way a secret field
            // works, and the only way a form that never shows Apple's private key back can
            // be saved without retyping it.
            $value = $given[$parameter->key] ?? null;
            $value = is_string($value) ? trim($value) : '';

            if ($value === '') {
                $value = trim((string) ($current[$parameter->key] ?? ''));
            }

            if ($value === '') {
                $missing['parameters.'.$parameter->key] = $parameter->label.' is required.';
            } else {
                $values[$parameter->key] = $value;
            }
        }

        if ($missing !== []) {
            throw ActionRefused::onFields('missing_parameters', $missing);
        }

        return $values;
    }

    /** Whether the provider is set up with a client secret (everyone but Apple). */
    public static function takesSecret(ProviderTemplate $template): bool
    {
        return $template->secretKind !== ClientSecretKind::SignedJwt;
    }

    /**
     * An OpenID provider's discovered endpoints, fetched NOW — at the catalogue's own
     * document when it names one (Intuit publishes its discovery document away from its
     * issuer) — so a mistyped Okta domain fails while somebody is still looking at it.
     *
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     *
     * @throws ActionRefused
     */
    public static function discover(ProviderTemplate $template, array $values): array
    {
        if (! $template->isOidc()) {
            return [];
        }

        $issuer = $template->issuerFor($values)
            ?? throw ActionRefused::because('incomplete_parameters', 'Fill in every field before enabling '.$template->name.'.', 'client_id');

        try {
            return app(OidcDiscovery::class)->fromIssuer($issuer, $template->discoveryUrlFor($values))->toConfig();
        } catch (Throwable $e) {
            throw ActionRefused::because('discovery_failed', 'We could not reach '.$template->name.' at '.$issuer.' — check the details. ('.$e->getMessage().')', 'client_id');
        }
    }

    /**
     * The provider an id names, if this caller may reach it — or a 404.
     *
     * THE OWNER IS IN THE QUERY, not in an `if` after it — the shape that once shipped a
     * cross-organization IDOR elsewhere. `organization_id` narrows it to one organization's;
     * a caller confined to an organization (its own console, a token signed in for it) is
     * narrowed to that one whether it says so or not, so the environment's providers are a
     * 404 to it rather than a write it may make. Only a CATALOGUE connection is reachable:
     * a company's own SSO connection is not a social provider.
     *
     * @throws ActionRefused
     */
    public static function reachable(ActionContext $context): Connection
    {
        $organizationId = $context->nullableString('organization_id') !== null
            ? EnterpriseReach::narrowedTo($context)
            : EnterpriseReach::confinedTo($context);

        return Connection::query()
            ->whereKey($context->string('id'))
            ->whereNotNull('provider')
            ->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->first() ?? throw ActionRefused::notFound('social provider');
    }

    /**
     * The extra scopes on file for a provider, as the administrator wrote them.
     *
     * @return list<string>
     */
    public static function extraScopes(Connection $connection): array
    {
        $stored = app(Connections::class)->config($connection)['extra_scopes'] ?? [];

        return is_array($stored) ? array_values(array_filter($stored, is_string(...))) : [];
    }

    /**
     * What one sign-in page offers — `OfferedSocialProviders` in the spec.
     *
     * @return array{organization_id: string|null, providers: list<array{id: string, provider: string|null, name: string, source: string, callback_uri: string}>, not_inherited: list<string>}
     */
    public static function offered(SignInProviders $providers, ?string $organizationId): array
    {
        return [
            'organization_id' => $organizationId,
            'providers' => array_map(static fn (Connection $connection): array => [
                'id' => $connection->id,
                'provider' => $connection->provider,
                'name' => $connection->name,
                // `environment` — inherited — or `organization`, its own in the environment's place.
                'source' => $connection->organization_id === null ? 'environment' : 'organization',
                'callback_uri' => self::callbackUri($connection),
            ], $providers->offeredTo($organizationId)),
            'not_inherited' => $organizationId === null ? [] : $providers->notInheritedBy($organizationId),
        ];
    }

    /** The redirect URI a provider must be given — it contains the connection's id. */
    public static function callbackUri(Connection $connection): string
    {
        return url(($connection->type === ConnectionType::OAuth2 ? '/sso/oauth2/' : '/sso/oidc/').$connection->id.'/callback');
    }

    /** The same URI for a connection that does not exist yet, under a reserved id. */
    public static function callbackUriFor(ProviderTemplate $template, string $id): string
    {
        return url(($template->isOidc() ? '/sso/oidc/' : '/sso/oauth2/').$id.'/callback');
    }

    /**
     * One enabled provider — `SocialProvider` in the spec.
     *
     * @return array<string, mixed>
     */
    public static function present(Connection $connection): array
    {
        $oauth2 = $connection->type === ConnectionType::OAuth2;

        return [
            'id' => $connection->id,
            'organization_id' => $connection->organization_id,
            // Whose it is: the environment's, offered on every sign-in page unless an
            // organization replaced or hid it, or one organization's own.
            'level' => $connection->organization_id === null ? 'environment' : 'organization',
            'provider' => $connection->provider,
            'name' => $connection->name,
            'protocol' => $oauth2 ? 'oauth2' : 'oidc',
            'status' => $connection->status->value,
            'enabled' => $connection->isActive(),
            'scopes' => self::extraScopes($connection),
            // THE REAL REDIRECT URI, which only exists once the connection does: the one
            // value the provider must be given, and the one most often got wrong.
            'callback_uri' => self::callbackUri($connection),
            'created_at' => Timestamp::of($connection->getAttribute('created_at')),
        ];
    }
}

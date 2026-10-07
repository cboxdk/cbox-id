<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\Input\Field;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Enums\ProviderCapability;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\ValueObjects\ProviderTemplate;

/**
 * Social sign-in providers as the management API takes and returns them. A helper, not an
 * action.
 *
 * WHAT IS NEVER RETURNED is the connection's configuration: the client secret, and Apple's
 * private key, go in and stay in. A provider is described by what it is and where its
 * callback is, which is everything a caller needs to finish setting it up at the provider.
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
            'provider' => $connection->provider,
            'name' => $connection->name,
            'protocol' => $oauth2 ? 'oauth2' : 'oidc',
            'status' => $connection->status->value,
            // THE REAL REDIRECT URI, which only exists once the connection does: the one
            // value the provider must be given, and the one most often got wrong.
            'callback_uri' => url(($oauth2 ? '/sso/oauth2/' : '/sso/oidc/').$connection->id.'/callback'),
            'created_at' => Timestamp::of($connection->getAttribute('created_at')),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Support\ClientIdUrl;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;

/**
 * A token stops being active when what it speaks for is gone: its organization was
 * suspended or archived, or its app was deleted.
 *
 * Access tokens are signed JWTs with a row each, and introspection asked only about the
 * row and the person. So a token minted for an organization before it was suspended went
 * on reading that organization's vault and pushing its app's manifest until it expired,
 * and a token of an app an administrator deleted to cut it off went on being reported
 * active to resource servers, the manifest endpoint, UserInfo and token exchange.
 *
 * Asked HERE because introspection is the one question every first-party consumer asks
 * (RequireScope, UserInfo, the decisions and frontend-session endpoints, `/oauth/introspect`
 * and token exchange), and because it is asked on every use: reactivating the organization
 * makes its unexpired tokens good again with no state to restore.
 *
 * A client-id-metadata-document client has no row by design (its `client_id` is the URL of
 * its document), so only a registered client's disappearance counts.
 */
final readonly class StandingTokenIntrospector implements TokenIntrospector
{
    public function __construct(private TokenIntrospector $inner) {}

    public function introspect(string $token): Introspection
    {
        $introspection = $this->inner->introspect($token);

        if (! $introspection->active) {
            return $introspection;
        }

        $organization = $introspection->claims['org'] ?? null;

        if (is_string($organization) && OrganizationStanding::isClosed($organization)) {
            return Introspection::inactive();
        }

        $clientId = $introspection->clientId;

        if (is_string($clientId) && $clientId !== '' && ! ClientIdUrl::isValid($clientId)
            && ! Client::query()->where('client_id', $clientId)->exists()) {
            return Introspection::inactive();
        }

        return $introspection;
    }

    public function revoke(string $jti): void
    {
        $this->inner->revoke($jti);
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\SupportAccess;

use Cbox\Id\OAuthServer\Contracts\AudienceResolver;
use Cbox\Id\OAuthServer\Exceptions\InvalidAudience;
use Cbox\Id\OAuthServer\Models\Client;

/**
 * The scopes a support session's tokens will carry — asked BEFORE the session starts.
 *
 * THE SESSION SAID MORE THAN ITS TOKENS. The framework stores the scopes it was asked for
 * (or, asked for none, the app's whole registration) narrowed only to the app's
 * registration. Every token then passes through the {@see AudienceResolver}, which does
 * the rest: once an app's scopes include a registered API's, the token is for that API,
 * and a scope nobody registered — `apps.manifest`, which lets an app publish its own
 * manifest — cannot ride on its audience. So the session row, its authorization codes and
 * the management API's answer all listed `apps.manifest`, and not one token carried it.
 *
 * Answered by asking the resolver the token endpoint's own question — these scopes, no
 * `resource` (a session's codes name none) — so resolving the answer again when a code is
 * redeemed changes nothing.
 */
final readonly class SupportSessionScopes
{
    public function __construct(private AudienceResolver $audiences) {}

    /**
     * What a session for this app, asked for `$requested` (none = the app's registration),
     * would carry in every token. Null when the app is not one this environment knows, so
     * the framework refuses it with its own reason.
     *
     * The framework's narrowing first — the app's registration, never `offline_access`,
     * because an acted grant has no refresh token — then the audience.
     *
     * @param  list<string>  $requested
     * @return list<string>|null
     *
     * @throws InvalidAudience when the scopes span two registered APIs, or none may be granted
     */
    public function settle(string $clientId, array $requested): ?array
    {
        $client = Client::query()->where('client_id', $clientId)->first();

        if ($client === null) {
            return null;
        }

        $scopes = $requested === []
            ? array_values($client->scopes)
            : array_values(array_filter($requested, static fn (string $scope): bool => $client->allows($scope)));

        $scopes = array_values(array_unique(array_filter($scopes, static fn (string $scope): bool => $scope !== 'offline_access')));

        return $scopes === [] ? [] : $this->audiences->resolve($client, $scopes, null)->scopes;
    }
}

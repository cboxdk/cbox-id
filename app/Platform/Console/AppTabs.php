<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Props\Shared\LinkTabProps;
use App\Platform\Locale\ConsoleLabels;
use App\Platform\Locale\ConsoleStrings;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\TokenEndpointAuthMethod;
use Cbox\Id\OAuthServer\Models\Client;

/**
 * THE APP PAGE'S TABS — each one a page of its own, with its own URL.
 *
 * One page held everything an app is: how to connect it, its details, its scopes, its
 * roles manifest, its secret and its deletion. Secrets with an overlap, token settings,
 * back-channel logout, a key prefix and promotion between environments would have made it
 * a page nobody could find anything on, so the app is five pages that share a header:
 *
 *  - OVERVIEW: what it is at a glance, connect it, its credentials, its roles manifest;
 *  - SIGN-IN & LOGOUT: where people are sent back to after signing in and out, and where
 *    the app is told somebody signed out (back-channel logout);
 *  - SCOPES: what it may ask for, and the audience its tokens will carry;
 *  - SECRETS: its live secrets, rotation with an overlap, revoking one;
 *  - SETTINGS: its name, token lifetime, token exchange, API keys, and deleting it.
 *
 * A tab the person cannot use is not drawn. Secrets exist only for an app that holds a
 * shared secret — a public app uses PKCE and an app that signs assertions holds keys — and
 * the settings are changes, which a tenant administrator looking at a platform app may not
 * make. Each tab's own controller asks the same questions, so an undrawn tab is also a
 * refused one.
 */
final readonly class AppTabs
{
    public const OVERVIEW = 'overview';

    public const SIGN_IN = 'sign-in';

    public const SCOPES = 'scopes';

    public const SECRETS = 'secrets';

    public const SETTINGS = 'settings';

    public function __construct(
        private ConsoleScope $scope,
        private ConsoleClients $clients,
    ) {}

    /**
     * @return list<LinkTabProps>
     */
    public function for(Client $client, string $current): array
    {
        $route = fn (string $name): string => route($this->scope->routeName($name), $client->id);
        $manages = $this->clients->mayManage($client);

        $tabs = [
            new LinkTabProps(self::OVERVIEW, ConsoleLabels::navigation('Overview'), $route('clients.show'), $current === self::OVERVIEW),
        ];

        if ($manages) {
            $tabs[] = new LinkTabProps(self::SIGN_IN, ConsoleStrings::message('console.app_tab_sign_in'), $route('clients.sign-in'), $current === self::SIGN_IN);
        }

        $tabs = [
            ...$tabs,
            new LinkTabProps(self::SCOPES, ConsoleLabels::navigation('Scopes'), $route('clients.scopes'), $current === self::SCOPES),
        ];

        if ($manages && self::holdsSharedSecret($client)) {
            $tabs[] = new LinkTabProps(self::SECRETS, ConsoleLabels::navigation('Secrets'), $route('clients.secrets'), $current === self::SECRETS);
        }

        if ($manages) {
            $tabs[] = new LinkTabProps(self::SETTINGS, ConsoleLabels::navigation('Settings'), $route('clients.settings'), $current === self::SETTINGS);
        }

        return $tabs;
    }

    /**
     * Whether the app authenticates with a shared secret — the only kind that has secrets
     * to list, rotate or revoke. The registry's own test, restated: confidential, and not
     * signing assertions with its own keys.
     */
    public static function holdsSharedSecret(Client $client): bool
    {
        return $client->type === ClientType::Confidential
            && $client->jwks === null
            && $client->token_endpoint_auth_method !== TokenEndpointAuthMethod::PrivateKeyJwt;
    }
}

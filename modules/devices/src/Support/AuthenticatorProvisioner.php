<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Finds or registers the authenticator app's OAuth client in the acting environment.
 *
 * Provisioning is self-service: the Trusted devices console page calls this on first
 * view, so enabling the module is a config change and nothing ever asks an operator to
 * run a command. The `cbox-id:devices:client` command drives the same code and remains
 * only for the cases a request can't cover — provisioning another environment, or
 * registering extra redirect URIs.
 *
 * Idempotence is load-bearing, not hygiene: `oauth_clients.name` carries no unique
 * index, and two clients with this name would leave {@see AuthenticatorClient::find()}
 * returning an arbitrary one of them — stranding every handset that enrolled against
 * the other. Hence the cache lock around first registration: two admins opening the
 * page in the same instant must still yield exactly one client.
 */
final class AuthenticatorProvisioner
{
    public function __construct(
        private readonly ClientRegistry $clients,
        private readonly Cache $cache,
        private readonly EnvironmentContext $context,
    ) {}

    /**
     * The authenticator client for the current environment, registering it if absent.
     *
     * @param  list<string>  $extraRedirectUris
     */
    public function ensure(string $host, array $extraRedirectUris = []): Client
    {
        $existing = AuthenticatorClient::find();

        if ($existing instanceof Client) {
            return $existing;
        }

        $register = fn (): Client => AuthenticatorClient::find() ?? $this->register($host, $extraRedirectUris);

        // One lock per environment — each environment mints its own client, so two
        // environments provisioning at once are not in each other's way.
        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            $key = 'cbox-id:devices:authenticator-client:'.($this->context->current()?->environmentKey() ?? 'default');

            $client = $store->lock($key, 10)->block(5, $register);

            if ($client instanceof Client) {
                return $client;
            }
        }

        return $register();
    }

    /**
     * @param  list<string>  $extraRedirectUris
     */
    private function register(string $host, array $extraRedirectUris): Client
    {
        $uris = AuthenticatorClient::redirectUris($host);

        foreach ($extraRedirectUris as $uri) {
            if ($uri !== '') {
                $uris[] = $uri;
            }
        }

        return $this->clients->register(new NewClient(
            name: AuthenticatorClient::NAME,
            // Public: a mobile binary cannot keep a secret, so it holds none. Per-device
            // identity comes from the DPoP key and the device registry row instead.
            type: ClientType::Public,
            redirectUris: array_values(array_unique($uris)),
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: AuthenticatorClient::SCOPES,
            // First-party, so enrolling one's own authenticator does not present a
            // consent screen asking the user to approve an app the platform ships.
            firstParty: true,
            organizationId: null,
        ))->client;
    }
}

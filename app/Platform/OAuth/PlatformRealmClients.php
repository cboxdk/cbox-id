<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Http\Middleware\RefuseCustomerClientsInPlatformRealm;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * NO CUSTOMER-CREATED OAUTH CLIENTS IN THE PLATFORM'S OWN REALM.
 *
 * The default environment of a multi-tenant deployment is the realm the platform's own
 * customers sign in to: one account per person, trusted by every first-party app. An OAuth
 * client there that an organization owns would let a customer put their own app in front
 * of that sign-in, on the one host every other customer trusts. So in that environment
 * only the platform's own clients exist: the ones the ENVIRONMENT owns (no
 * `organization_id`), which only the platform's operators and its own commands create.
 *
 * Two halves, and both are needed:
 *
 *  - CREATING: an organization-scoped client is refused in the default environment, by
 *    every door. The actions say so ({@see refusalFor()}); the model refuses the write
 *    whoever asked ({@see guard()}), so the next door starts refused too.
 *  - SERVING: a client that is already there is not served. `/oauth/*` answers 404 for it
 *    ({@see RefuseCustomerClientsInPlatformRealm}), whatever the host
 *    gates above it decide.
 *
 * A client that registered ITSELF for the platform's `/mcp` (no organization, a
 * registration access token) is not a customer's app and is left to the gate that already
 * confines it to that audience.
 *
 * `cbox-id.realm.platform_clients_only`, off by default. A customer's own environment is
 * never asked, and neither is a single-tenant install, where the default environment IS
 * the product and an organization's apps are its ordinary business.
 */
final readonly class PlatformRealmClients
{
    public function __construct(
        private PlaneResolver $planes,
        private EnvironmentResolver $resolver,
        private EnvironmentContext $environments,
    ) {}

    public function enabled(): bool
    {
        return config('cbox-id.realm.platform_clients_only', false) === true && $this->planes->isMultiTenant();
    }

    /** The platform realm's environment, or null when the rule is off or there is none. */
    public function realm(): ?string
    {
        return $this->enabled() ? $this->resolver->defaultEnvironment()?->environmentKey() : null;
    }

    /** Whether the rule governs `$environmentId` (the current environment when null). */
    public function governs(?string $environmentId = null): bool
    {
        $realm = $this->realm();
        $environmentId ??= $this->environments->current()?->environmentKey();

        return $realm !== null && $environmentId === $realm;
    }

    /** Whether a client owned by `$organizationId` may be created here, in the current environment. */
    public function mayCreate(?string $organizationId): bool
    {
        return $organizationId === null || ! $this->governs();
    }

    /** Whether this client may exist, be changed and be served where it lives. */
    public function admits(Client $client): bool
    {
        return $client->organization_id === null || ! $this->governs($client->environment_id);
    }

    /**
     * Whether `/oauth/*` must refuse `$clientId`: it names an organization's client in the
     * platform realm. Asked by NAME of the environment, never through the ambient scope, so
     * the answer does not depend on which middleware has run.
     */
    public function refusesToServe(string $clientId): bool
    {
        $realm = $this->realm();

        if ($realm === null || $clientId === '') {
            return false;
        }

        return $this->environments->withoutScope(
            static fn (): bool => Client::query()
                ->where('environment_id', $realm)
                ->where('client_id', $clientId)
                ->whereNotNull('organization_id')
                ->exists(),
        ) === true;
    }

    /**
     * The backstop under every door: an organization's client is not written into the
     * platform realm, and an existing one is not handed to an organization. Deleting one,
     * and the bookkeeping writes on a row that is already there, are left alone so what
     * exists can be cleaned up.
     */
    public static function guard(): void
    {
        $refuse = static function (Client $client): void {
            if ($client->organization_id === null) {
                return;
            }

            if ($client->exists && ! $client->isDirty('organization_id')) {
                return;
            }

            $rule = app(self::class);
            $environmentId = $client->environment_id ?? app(EnvironmentContext::class)->current()?->environmentKey();

            if ($rule->governs(is_string($environmentId) ? $environmentId : null)) {
                throw new AuthorizationException(self::MESSAGE);
            }
        };

        Client::creating($refuse);
        Client::updating($refuse);
    }

    public const MESSAGE = 'Organizations cannot own OAuth clients in the platform\'s own environment. Register the app in one of your own environments.';
}

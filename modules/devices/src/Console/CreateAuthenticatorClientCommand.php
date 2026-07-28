<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Console;

use Cbox\Id\Devices\Support\AuthenticatorClient;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Console\Command;

/**
 * Provisions the OAuth client the authenticator app signs in as.
 *
 * WHY A COMMAND, AND WHY PER ENVIRONMENT
 * --------------------------------------
 * `oauth_clients.client_id` carries a GLOBAL unique index, not a composite on
 * (environment_id, client_id). So there is no well-known client id that every
 * environment can share, and none that can be baked into a single App Store binary
 * serving more than one tenant. Each environment gets its own, and the app discovers it
 * at runtime from `/.well-known/cbox-authenticator` — which is why that endpoint is
 * load-bearing rather than a nicety.
 *
 * WHY NOT DYNAMIC CLIENT REGISTRATION
 * -----------------------------------
 * DCR is disabled by default, and turning it on would be a security regression here.
 * Its `allowed_scopes` list silently DROPS anything outside
 * openid/profile/email/offline_access/organizations, so a self-registered client could
 * never hold `devices.manage`; widening that list globally would hand the
 * device-approval scopes to every self-registering client on the platform. DCR also
 * never sets `first_party`, so every install would hit the consent screen, and its
 * `protected` mode wants an initial access token inside a binary that ships as readable
 * PHP.
 *
 * A public client's `client_id` is not a credential, so nothing secret is created here.
 */
class CreateAuthenticatorClientCommand extends Command
{
    protected $signature = 'cbox-id:devices:client
        {--environment= : The environment to provision in. Defaults to the configured default.}
        {--redirect=* : Extra redirect URIs, added to the derived defaults.}
        {--host= : The environment host used to derive the HTTPS redirect URI.}';

    protected $description = 'Provision the OAuth client used by the Cbox ID authenticator app';

    public function handle(EnvironmentContext $context, ClientRegistry $clients): int
    {
        $environmentId = $this->stringOption('environment')
            ?? AuthenticatorClient::defaultEnvironmentId();

        if ($environmentId === '') {
            $this->error('No environment given and none configured. Pass --environment.');

            return self::FAILURE;
        }

        return $context->runAs(
            GenericEnvironment::of($environmentId),
            fn (): int => $this->provision($clients, $environmentId),
        );
    }

    private function provision(ClientRegistry $clients, string $environmentId): int
    {
        // Idempotent: re-running must not mint a second client and silently strand every
        // handset that enrolled against the first one.
        $existing = AuthenticatorClient::find();

        if ($existing instanceof Client) {
            $this->info("Authenticator client already provisioned in {$environmentId}.");
            $this->line("  client_id: {$existing->client_id}");

            return self::SUCCESS;
        }

        $registered = $clients->register(new NewClient(
            name: AuthenticatorClient::NAME,
            // Public: a mobile binary cannot keep a secret, so it holds none. Per-device
            // identity comes from the DPoP key and the device registry row instead.
            type: ClientType::Public,
            redirectUris: $this->redirectUris(),
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: AuthenticatorClient::SCOPES,
            // First-party, so enrolling one's own authenticator does not present a
            // consent screen asking the user to approve an app the platform ships.
            firstParty: true,
            organizationId: null,
        ));

        $this->info("Provisioned the authenticator client in {$environmentId}.");
        $this->line('  client_id: '.$registered->client->client_id);
        $this->newLine();
        $this->line('The app discovers this at GET /.well-known/cbox-authenticator; it does');
        $this->line('not need to be copied anywhere.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function redirectUris(): array
    {
        $host = $this->stringOption('host') ?? AuthenticatorClient::hostFromAppUrl();

        $uris = AuthenticatorClient::redirectUris($host);

        foreach ((array) $this->option('redirect') as $uri) {
            if (is_string($uri) && $uri !== '') {
                $uris[] = $uri;
            }
        }

        return array_values(array_unique($uris));
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

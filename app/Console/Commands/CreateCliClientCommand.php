<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CliClient;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Console\Command;

/**
 * Provision the OAuth client the `cbox` CLI signs in as.
 *
 * A COMMAND RATHER THAN A CONSOLE CLICK, deliberately. This is the one operator
 * action standing between an environment and `cbox login`, and it has to be run
 * on production by somebody who is allowed to: a command is repeatable,
 * reviewable in a diff, visible in shell history, and identical for a customer
 * running their own deployment. A thing done once by hand in a UI is a thing
 * nobody can reproduce when it needs doing again.
 *
 * IDEMPOTENT. Re-running must not mint a second client — every machine that has
 * signed in holds tokens issued to the first, and a second one with the same
 * name would make {@see CliClient::find()} return an arbitrary one of the two.
 *
 * NOT DYNAMIC CLIENT REGISTRATION, for the hosted platform. DCR is disabled by
 * default and opening it on a public issuer accepts anonymous client creation
 * from anyone. Self-hosted deployments are the case DCR is for, and the CLI
 * self-registers there — see `CBOX_ID_DCR_MODE`.
 */
class CreateCliClientCommand extends Command
{
    protected $signature = 'cbox-id:cli:client
        {--environment= : The environment to provision in. Defaults to the configured default.}';

    protected $description = 'Provision the OAuth client used by the cbox CLI';

    public function handle(EnvironmentContext $context, ClientRegistry $clients): int
    {
        // `config()` is mixed, and a cast of mixed is a cast that can surprise. Narrowed
        // rather than forced: a non-string default is a misconfiguration, not something to
        // stringify silently.
        $configured = config('cbox-id.environments.default', '');

        $environmentId = $this->stringOption('environment')
            ?? (is_string($configured) ? $configured : '');

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
        $issuer = $this->issuerFor(app(IssuerResolver::class), $environmentId);

        $existing = CliClient::find();

        if ($existing instanceof Client) {
            // The sign-in command too, because "already provisioned" is usually
            // asked by somebody who wants to know what to run next — and the
            // answer is not guessable from the deployment's own URL.
            $this->info("The cbox CLI client is already provisioned in {$environmentId}.");
            $this->line("  client_id: {$existing->client_id}");
            $this->newLine();
            $this->line('  <options=bold>cbox login --issuer '.$issuer.'</>');

            return self::SUCCESS;
        }

        $client = $clients->register(new NewClient(
            name: CliClient::NAME,
            // Public: a binary on a developer's laptop cannot keep a secret.
            type: ClientType::Public,
            // None. The device grant has no redirect — that is the point of it.
            redirectUris: [],
            grantTypes: CliClient::GRANTS,
            scopes: CliClient::SCOPES,
            // First-party: signing in to our own CLI should not ask a Cbox user
            // to approve Cbox.
            firstParty: true,
            organizationId: null,
        ))->client;

        $this->info("Provisioned the cbox CLI client in {$environmentId}.");
        $this->line('  client_id: '.$client->client_id);
        $this->newLine();
        $this->line('Nothing to copy anywhere: the CLI reads this from');
        $this->line('GET '.$issuer.'/.well-known/cbox-cli');
        $this->newLine();
        $this->line('  <options=bold>cbox login --issuer '.$issuer.'</>');

        return self::SUCCESS;
    }

    /**
     * The address a person actually signs in against.
     *
     * THE ENVIRONMENT'S OWN ISSUER, not `app.url`. On a multi-tenant deployment
     * `app.url` is the platform ROOT, which serves no discovery document at all —
     * it is an issuer for nobody, by design ({@see EnforcePlane}). Printing it
     * sent an operator to a host that 404s on `.well-known/openid-configuration`,
     * with the client they had just provisioned sitting on a different name.
     * Measured on the hosted deployment: cboxid.com answered 404 and
     * cbox.cboxid.com answered 200.
     */
    private function issuerFor(IssuerResolver $issuers, string $environmentId): string
    {
        return $issuers->forEnvironment($environmentId);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

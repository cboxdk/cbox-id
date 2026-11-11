<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

use App\Platform\Actions\Approvals\StepUpClient;
use App\Platform\Surfaces\Surfaces;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ClientBlueprint;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\PlatformRoot;
use RuntimeException;

/**
 * THE CONSOLE'S OWN OAUTH CLIENT, in login-host mode.
 *
 * The console on the app host signs people in at the login host like any other Cbox
 * application: authorization code with PKCE, as a confidential client. This is that
 * client: platform-owned (no organization), first-party (no consent screen), held in the
 * default environment, and found by its name (among the environment's own clients, which
 * only the platform creates) the way {@see StepUpClient}
 * is, so nothing but its secret has to be configured.
 *
 * The secret is the deployment's to keep (`CBOX_ID_CONSOLE_CLIENT_SECRET`). The registry
 * stores only a hash, so {@see provision()} hands the plaintext back exactly once, when the
 * client is created or its secret is rotated; `php artisan cbox-id:console:client` prints it
 * for the operator to put in the secret store.
 */
final class ConsoleClient
{
    public const string NAME = 'Cbox ID console';

    public const string CALLBACK_PATH = '/auth/callback';

    public const string BACKCHANNEL_LOGOUT_PATH = '/auth/backchannel-logout';

    public const string SIGNED_OUT_PATH = '/signed-out';

    /** `openid` alone: the console reads the person from its own database by `sub`. */
    public const array SCOPES = ['openid'];

    private ?Client $memo = null;

    public function __construct(
        private readonly PlatformRoot $root,
        private readonly ClientRegistry $clients,
        private readonly Surfaces $surfaces,
    ) {}

    /** The registered client, or null until it has been provisioned. */
    public function find(): ?Client
    {
        return $this->memo ??= $this->root->run(
            static fn (): ?Client => Client::query()
                ->where('name', self::NAME)
                ->whereNull('organization_id')
                ->oldest('id')
                ->first(),
        );
    }

    /** The configured secret, or null when the deployment has not been given one. */
    public function secret(): ?string
    {
        $secret = config('cbox-id.console.client_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /** Whether the flow can run: a client exists and the deployment holds its secret. */
    public function ready(): bool
    {
        return $this->secret() !== null && $this->find() !== null;
    }

    public function redirectUri(): string
    {
        return $this->onAppHost(self::CALLBACK_PATH);
    }

    public function postLogoutRedirectUri(): string
    {
        return $this->onAppHost(self::SIGNED_OUT_PATH);
    }

    public function backchannelLogoutUri(): string
    {
        return $this->onAppHost(self::BACKCHANNEL_LOGOUT_PATH);
    }

    /**
     * An address on the app host, the same whoever asks: the command that registers it and
     * the request that sends it must agree to the byte, and a command has no request to
     * take a scheme or a port from. Both come from `app.url`; the host is the app host.
     */
    private function onAppHost(string $path): string
    {
        $appUrl = config('app.url');
        $appUrl = is_string($appUrl) ? $appUrl : '';
        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        $port = parse_url($appUrl, PHP_URL_PORT);

        return (is_string($scheme) && $scheme !== '' ? $scheme : 'https').'://'
            .$this->surfaces->consoleHost()
            .(is_int($port) ? ':'.$port : '')
            .$path;
    }

    /**
     * Create the client, or bring an existing one's addresses in line with the app host.
     * Safe to run on every deploy.
     *
     * @return array{client: Client, created: bool, secret: ?string} the secret only when one was minted
     */
    public function provision(bool $rotate = false): array
    {
        if (! $this->surfaces->split() || $this->surfaces->consoleHost() === null) {
            throw new RuntimeException('The console client belongs to login-host mode: set CBOX_ID_LOGIN_HOST and CBOX_ID_CONSOLE_HOST first.');
        }

        $this->memo = null;

        /** @var array{client: Client, created: bool, secret: ?string} $result */
        $result = $this->root->run(function () use ($rotate): array {
            $existing = $this->find();

            if ($existing === null) {
                $registered = $this->clients->register(new NewClient(
                    name: self::NAME,
                    type: ClientType::Confidential,
                    redirectUris: [$this->redirectUri()],
                    grantTypes: ['authorization_code'],
                    scopes: self::SCOPES,
                    firstParty: true,
                    postLogoutRedirectUris: [$this->postLogoutRedirectUri()],
                    backchannelLogoutUri: $this->backchannelLogoutUri(),
                    backchannelLogoutSessionRequired: true,
                ));

                return ['client' => $registered->client, 'created' => true, 'secret' => $registered->secret];
            }

            // A client of this name from before the console was a relying party (its
            // sign-in went through another environment) is the same client: it is brought
            // in line here, old redirect address and all. Only a public one cannot be,
            // because a client's type is not something an update may change.
            if ($existing->type !== ClientType::Confidential) {
                throw new RuntimeException('A public client named "'.self::NAME.'" ('.$existing->client_id.') is in the way. Delete it, then run this again.');
            }

            $held = $this->clients->blueprint($existing);

            $client = $this->clients->update($existing, new ClientBlueprint(
                name: self::NAME,
                type: $held->type,
                tokenEndpointAuthMethod: $held->tokenEndpointAuthMethod,
                grantTypes: ['authorization_code'],
                redirectUris: [$this->redirectUri()],
                postLogoutRedirectUris: [$this->postLogoutRedirectUri()],
                scopes: self::SCOPES,
                firstParty: true,
                manifestUrl: $held->manifestUrl,
                accessTokenTtl: $held->accessTokenTtl,
                backchannelLogoutUri: $this->backchannelLogoutUri(),
                backchannelLogoutSessionRequired: true,
                apiKeyPrefix: $held->apiKeyPrefix,
            ));

            return [
                'client' => $client,
                'created' => false,
                'secret' => $rotate ? $this->clients->rotateSecret($client)->secret : null,
            ];
        });

        $this->memo = $result['client'];

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Console\Commands\CreateCliClientCommand;
use App\Console\Commands\InstallCommand;
use App\Http\Controllers\Api\CliBootstrapController;
use App\Mcp\McpProtectedResources;
use App\Platform\OAuth\DelegatedAccess;
use App\Platform\OAuth\RootDelegatedAccess;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ClientBlueprint;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\OAuthServer\ValueObjects\ProtectedResource;

/**
 * The OAuth client the `cbox` CLI signs in as.
 *
 * ONE PER ENVIRONMENT, DISCOVERED RATHER THAN BAKED IN. `oauth_clients.client_id`
 * is generated (`cid_…`) and carries a GLOBAL unique index, so there is no
 * well-known value a binary can be compiled with — the same constraint the
 * authenticator app has, answered the same way: the CLI asks the host it was
 * pointed at, at `/.well-known/cbox-cli`, and gets that environment's answer.
 *
 * PUBLIC, WITH TWO GRANTS. A binary on somebody's laptop cannot keep a secret,
 * so it holds none; a client secret shipped inside a PHAR is a secret
 * published. The device grant is how a terminal signs in without a browser on
 * the same machine — over SSH, in CI, in a container — and `refresh_token` is
 * what stops the session ending an hour into somebody's work.
 *
 * FIRST-PARTY, so signing in to our own CLI does not present a consent screen
 * asking a Cbox user to approve Cbox. The device page still lists what it may do.
 *
 * AND IT SIGNS IN TO THE MANAGEMENT PLANE. Its scopes are the sign-in ones plus
 * every scope of the host's `/mcp` resource ({@see McpProtectedResources}), and
 * `cbox login` names that resource as its RFC 8707 `resource` — the framework
 * honours it on the device grant — so the one token it holds is good at `/mcp`
 * and on the REST API alike, as the person who approved the code and within what
 * they may do themselves:
 *
 *  - on an ENVIRONMENT's host, as one of its own subjects ({@see DelegatedAccess}):
 *    that environment's management plane, and their own account there;
 *  - at the PLATFORM ROOT, as one of a workspace's team or an operator
 *    ({@see RootDelegatedAccess}): the workspace, every environment of it they
 *    administer (named per call — the MCP tools' `environment` argument, the REST
 *    `Cbox-Environment` header), their own account, and for an operator the
 *    deployment. That is the `cbox login` most people who run a workspace want, and
 *    the root's client is provisioned for it like any other
 *    (`php artisan cbox-id:cli:client --environment=<root>`; the installer does it).
 */
final class CliClient
{
    public const NAME = 'Cbox CLI';

    /**
     * The sign-in half of {@see scopes()}.
     *
     * `offline_access` is not optional here: without it there is no refresh
     * token, and every session ends when the access token does — an hour in,
     * mid-work, which is how people end up pasting long-lived API keys instead.
     *
     * @var list<string>
     */
    public const SCOPES = ['openid', 'profile', 'email', 'offline_access'];

    /** @var list<string> */
    public const GRANTS = ['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token'];

    /**
     * This environment's CLI client, if it has been provisioned.
     *
     * Scoped by the tenancy the caller is already inside — the same lookup the
     * authenticator uses, and it is by NAME because the id is generated.
     */
    public static function find(): ?Client
    {
        return Client::query()->where('name', self::NAME)->first();
    }

    /**
     * This host's `/mcp` — the `resource` the CLI signs in for: an environment's management
     * plane, or at the platform root every plane a workspace's people use — or null when
     * this host declares none.
     */
    public static function resource(): ?ProtectedResource
    {
        return app(ProtectedResources::class)->forMetadataPath(ProtectedResource::WELL_KNOWN.McpProtectedResources::PATH);
    }

    /**
     * Everything the CLI signs in for: {@see SCOPES}, and every scope of the management
     * plane. Read from the plane rather than listed, so an action added to the app is a
     * scope the CLI may ask for with no edit here.
     *
     * @return list<string>
     */
    public static function scopes(): array
    {
        return array_values(array_unique([...self::SCOPES, ...(self::resource()->scopes ?? [])]));
    }

    /**
     * Provision this environment's CLI client, or bring an existing one up to
     * {@see scopes()}. Call inside the environment it belongs to.
     *
     * IDEMPOTENT, and it only ever ADDS scopes: a client provisioned before the CLI could
     * sign in to the management plane keeps everything it had, gains the plane's scopes —
     * the ceiling every device request is held to — and is not touched again once it has
     * them. Every machine already signed in holds tokens issued to it, so it is never
     * replaced. Shared by {@see CreateCliClientCommand} and {@see InstallCommand}.
     *
     * @return array{0: Client, 1: bool} the client, and whether it was created just now
     */
    public static function provision(ClientRegistry $clients): array
    {
        $existing = self::find();

        if (! $existing instanceof Client) {
            return [$clients->register(new NewClient(
                name: self::NAME,
                // Public: a binary on a developer's laptop cannot keep a secret.
                type: ClientType::Public,
                // None. The device grant has no redirect — that is the point of it.
                redirectUris: [],
                grantTypes: self::GRANTS,
                scopes: self::scopes(),
                // First-party: signing in to our own CLI should not ask a Cbox user to
                // approve Cbox.
                firstParty: true,
                organizationId: null,
            ))->client, true];
        }

        $missing = array_values(array_diff(self::scopes(), $existing->scopes));

        if ($missing === []) {
            return [$existing, false];
        }

        return [$clients->update($existing, ClientBlueprint::fromClient($existing)->withScopes([...$existing->scopes, ...$missing])), false];
    }

    /**
     * What `/.well-known/cbox-cli` says about signing in ({@see CliBootstrapController}).
     *
     * The scopes are the ones $client is REGISTERED for, not the ones it could be: a device
     * request naming a scope the client does not hold is refused outright, so advertising
     * the management plane to a client provisioned before it existed would break `cbox
     * login` there until somebody re-ran the provisioning command. It signs in for less
     * instead, and gains the plane when the client does.
     *
     * @return array{scopes: list<string>, grant_types: list<string>, resource: string|null}
     */
    public static function signIn(Client $client): array
    {
        return [
            'scopes' => array_values(array_intersect(self::scopes(), $client->scopes)),
            'grant_types' => self::GRANTS,
            'resource' => self::resource()?->identifier,
        ];
    }
}

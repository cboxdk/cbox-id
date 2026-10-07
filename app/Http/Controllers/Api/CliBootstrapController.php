<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\CliClient;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Http\JsonResponse;

/**
 * What the `cbox` CLI needs to know before it can sign in here.
 *
 * IT EXISTS BECAUSE THE CLIENT ID CANNOT BE COMPILED IN. `oauth_clients.client_id`
 * is generated and globally unique, so every environment mints its own and one
 * binary can serve all of them only by asking the host it was pointed at. The
 * authenticator app has the same constraint and the same answer — see
 * `/.well-known/cbox-authenticator`.
 *
 * UNAUTHENTICATED, AND IT DISCLOSES NOTHING. A public client's `client_id` is
 * not a credential: possessing it grants nothing without a user approving a
 * device code. Everything else here is already public in
 * `/.well-known/openid-configuration`.
 *
 * A HOST WITHOUT ONE ANSWERS 404, in the same shape as one where the feature is
 * unconfigured — so probing tells somebody "no CLI client here" and nothing
 * about what else this deployment does or does not run.
 */
final class CliBootstrapController
{
    public function __invoke(IssuerResolver $issuers): JsonResponse
    {
        $client = CliClient::find();

        if (! $client instanceof Client) {
            return new JsonResponse([
                'error' => 'not_found',
                'message' => 'This host has no cbox CLI client. An operator provisions one with '
                    .'`php artisan cbox-id:cli:client`.',
            ], 404);
        }

        $issuer = $issuers->issuer();

        $signIn = CliClient::signIn();

        return new JsonResponse([
            'issuer' => $issuer,
            'client_id' => $client->client_id,
            // The sign-in scopes AND the management plane's: `cbox login` asks for these.
            'scopes' => $signIn['scopes'],
            'grant_types' => $signIn['grant_types'],
            // The RFC 8707 `resource` to name when redeeming the device code, so the token
            // is audienced to this environment's management plane — good at `/mcp` and on
            // the REST environment API alike, as the person who approved it.
            'resource' => $signIn['resource'],
            // Where the management planes live, so the CLI can save an endpoint
            // alongside the sign-in rather than making one up from the issuer.
            'api_base' => rtrim($issuer, '/').'/api/v1',
            'mcp_url' => $signIn['resource'],
        ]);
    }
}

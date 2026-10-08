<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\OAuth;

use App\Platform\OAuth\RootMcpOAuth;
use App\Platform\PlaneResolver;
use Cbox\Id\Api\Http\Controllers\RegistrationController;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /oauth/register` (RFC 7591) — the framework's registration, and at the PLATFORM
 * ROOT that registration held to the MCP profile.
 *
 * On every other host this is the framework's controller, unchanged. At the root an MCP
 * client registers itself for the root's `/mcp` ({@see RootMcpOAuth}), and three things
 * differ:
 *
 *  - ONLY THE `mcp` PROFILE. A public client, PKCE, https or loopback redirects, the
 *    scopes of `/mcp` and `offline_access`. A deployment that set another mode — `open`
 *    would register confidential clients with any allowed grant, `protected` a
 *    pre-authorized one — registers nothing at the root rather than that: the root is not
 *    where a deployment's own apps are registered.
 *  - NO RFC 7592 MANAGEMENT. The framework answers with a registration access token and a
 *    `registration_client_uri`; at the root that endpoint is not served, so the response
 *    does not advertise it. RFC 7591 §3.2.1 makes both optional. A client that wants other
 *    metadata registers again, and the unused one is swept (`prune.retention_days.oauth_clients`).
 *  - RECORDED with the address it came from, beside the framework's own `app.created`.
 *
 * The per-address hourly ceiling is the framework's (`dynamic_registration.max_per_ip_per_hour`),
 * on the route.
 */
final class ClientRegistrationController
{
    public function __construct(
        private readonly RegistrationController $framework,
        private readonly PlaneResolver $planes,
        private readonly RootMcpOAuth $root,
        private readonly ClientRegistry $clients,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->planes->onAccountPlane()) {
            return ($this->framework)($request);
        }

        if (config('cbox-id.oauth.dynamic_registration.mode') !== 'mcp') {
            return new JsonResponse([
                'error' => 'access_denied',
                'error_description' => 'dynamic client registration at the platform root is offered in the mcp profile only',
            ], 403);
        }

        $response = ($this->framework)($request);
        $document = $response->getData(true);

        if ($response->getStatusCode() !== 201 || ! is_array($document)) {
            return $response;
        }

        unset($document['registration_access_token'], $document['registration_client_uri']);

        $client = is_string($id = $document['client_id'] ?? null) ? $this->clients->byClientId($id) : null;

        if ($client !== null) {
            $this->root->recordRegistration($client, $request);
        }

        return new JsonResponse($document, 201, $response->headers->all());
    }
}

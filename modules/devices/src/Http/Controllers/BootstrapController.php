<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Http\Controllers;

use Cbox\Id\Devices\Support\AuthenticatorClient;
use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Http\JsonResponse;

/**
 * What the authenticator app needs to know before it can sign in anywhere.
 *
 * Unauthenticated by design, and it discloses nothing: a public client's `client_id` is
 * not a credential — the whole point of PKCE and DPoP is that possessing it grants
 * nothing without the per-install key. Everything else here is already public in
 * `/.well-known/openid-configuration`.
 *
 * This exists because `oauth_clients.client_id` is globally unique, so each environment
 * mints its own and no single value can be compiled into an App Store binary. The app
 * asks the host it was pointed at, and gets that environment's answer.
 */
final class BootstrapController
{
    public function __invoke(IssuerResolver $issuers): JsonResponse
    {
        if (! DeviceConfig::bool('id-devices.enabled', false)) {
            return $this->notProvisioned();
        }

        $client = AuthenticatorClient::find();

        // Answers identically to a disabled module. An attacker probing hosts learns
        // only "no authenticator here", not whether the feature exists but is unconfigured.
        if (! $client instanceof Client) {
            return $this->notProvisioned();
        }

        $issuer = $issuers->issuer();

        return new JsonResponse([
            'issuer' => $issuer,
            'client_id' => $client->client_id,
            'scopes' => AuthenticatorClient::SCOPES,
            'redirect_uris' => $client->redirect_uris,
            'api_base' => rtrim($issuer, '/').'/api/v1',
        ]);
    }

    private function notProvisioned(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'not_found',
            'message' => 'This host has no authenticator client.',
        ], 404);
    }
}

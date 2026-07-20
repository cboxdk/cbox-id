<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireScope;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Exceptions\LeaseDenied;
use Cbox\Id\TokenVault\Exceptions\SecretNotFound;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer-facing Token Vault API. A backend provisions and grants downstream
 * credentials with a `vault.manage` token; an authorized agent client redeems one
 * for immediate use with a `vault.lease` token. The vault keeps every secret sealed
 * at rest and refuses a lease that has no live grant — this controller only maps
 * HTTP to those guarantees and never widens them.
 *
 * Authentication and scope are enforced by the {@see RequireScope}
 * middleware, which places the verified token on the request as `cbox_token`.
 */
final class VaultController extends Controller
{
    /**
     * The organization the CALLER is entitled to act within, taken from the verified
     * access token — never from the request body.
     *
     * This is the vault's tenancy boundary. Reading it from input would let any caller
     * label a secret with, or reach a secret belonging to, an organization that is not
     * theirs; the body previously carried `owner_type`/`owner_id` directly. A token with
     * no org claim addresses only unowned (platform) secrets, which is the operator's
     * own set — not a wildcard.
     */
    private function owner(Request $request): ?VaultOwner
    {
        $token = $request->attributes->get('cbox_token');

        if (! $token instanceof Introspection) {
            return null;
        }

        $org = $token->claims['org'] ?? null;

        return is_string($org) && $org !== '' ? VaultOwner::organization($org) : null;
    }

    /** Ingest a downstream credential, sealed at rest. */
    public function store(Request $request, SecretVault $vault): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'provider' => ['required', 'string', 'max:100'],
            'secret' => ['required', 'string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $secret = $vault->store(
            $request->string('name')->toString(),
            $request->string('provider')->toString(),
            $request->string('secret')->toString(),
            $this->owner($request),
            $request->date('expires_at'),
        );

        return new JsonResponse($this->secretPayload($secret), 201);
    }

    /** Rotate the sealed value, keeping the secret id (and grants) stable. */
    public function rotate(Request $request, string $id, SecretVault $vault): JsonResponse
    {
        $request->validate(['secret' => ['required', 'string']]);

        try {
            $secret = $vault->rotate($id, $request->string('secret')->toString(), $this->owner($request));
        } catch (SecretNotFound) {
            return $this->notFound();
        }

        return new JsonResponse($this->secretPayload($secret));
    }

    /** Revoke a secret permanently — no future lease can open it. */
    public function revoke(Request $request, string $id, SecretVault $vault): JsonResponse
    {
        try {
            $vault->revoke($id, $this->owner($request));
        } catch (SecretNotFound) {
            return $this->notFound();
        }

        return new JsonResponse(null, 204);
    }

    /** Authorize an agent client to lease a secret. */
    public function grant(Request $request, string $id, SecretVault $vault): JsonResponse
    {
        $request->validate([
            'client_id' => ['required', 'string', 'max:200'],
            'max_ttl_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $grant = $vault->grant(
                $id,
                $request->string('client_id')->toString(),
                $this->owner($request),
                $request->filled('max_ttl_seconds') ? $request->integer('max_ttl_seconds') : null,
            );
        } catch (SecretNotFound) {
            return $this->notFound();
        }

        return new JsonResponse([
            'secret_id' => $grant->secret_id,
            'client_id' => $grant->client_id,
            'max_ttl_seconds' => $grant->max_ttl_seconds,
        ], 201);
    }

    /** Revoke an agent's authorization (idempotent). */
    public function revokeGrant(Request $request, string $id, string $clientId, SecretVault $vault): JsonResponse
    {
        $vault->revokeGrant($id, $clientId, $this->owner($request));

        return new JsonResponse(null, 204);
    }

    /**
     * Broker the credential to the calling agent for immediate use. The caller is
     * identified by the access token's `client_id`; deny-by-default and uniform —
     * any refusal is a single 403 with no detail, preserving the no-enumeration
     * property of the vault.
     */
    public function lease(Request $request, string $id, SecretVault $vault): JsonResponse
    {
        $request->validate(['purpose' => ['required', 'string', 'max:200']]);

        $token = $request->attributes->get('cbox_token');
        if (! $token instanceof Introspection || $token->clientId === null) {
            // The RequireScope middleware guarantees this; narrow for the type system.
            return $this->challengeExpired();
        }

        try {
            $lease = $vault->lease($id, $token->clientId, $request->string('purpose')->toString(), $this->owner($request));
        } catch (LeaseDenied) {
            return new JsonResponse(['error' => 'lease_denied'], 403);
        }

        return new JsonResponse([
            'secret_id' => $lease->secretId,
            'provider' => $lease->provider,
            'secret' => $lease->secret,
            'expires_at' => $lease->expiresAt->format(DATE_ATOM),
        ]);
    }

    /** @return array<string, mixed> */
    private function secretPayload(VaultSecret $secret): array
    {
        return [
            'id' => $secret->id,
            'name' => $secret->name,
            'provider' => $secret->provider,
            'owner_type' => $secret->owner_type,
            'owner_id' => $secret->owner_id,
            'expires_at' => $secret->expires_at?->format(DATE_ATOM),
            'revoked' => $secret->isRevoked(),
        ];
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found'], 404);
    }

    private function challengeExpired(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'invalid_token', 'error_description' => 'The access token is invalid or expired.'],
            401,
            ['WWW-Authenticate' => 'Bearer error="invalid_token"'],
        );
    }
}

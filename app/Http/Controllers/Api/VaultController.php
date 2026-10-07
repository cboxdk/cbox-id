<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireScope;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Exceptions\LeaseDenied;
use Cbox\Id\TokenVault\Exceptions\SecretNotFound;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Illuminate\Auth\Access\AuthorizationException;
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
     * The organization the CALLER is entitled to act within — decided by the APP the token
     * was issued to, and never by the token's `org` claim alone.
     *
     * This is the vault's tenancy boundary. It used to be the `org` claim and nothing else,
     * and for a user-delegated token that claim is the PERSON's current organization, not
     * the app's. So any member — not just an administrator — who consented to an app
     * holding `vault.manage` handed that app rotate, revoke and grant over their whole
     * organization's vault, even when the app belonged to another organization.
     *
     * Now:
     *  - an app an ORGANIZATION owns acts in that organization only, and a token that names
     *    a different one is refused outright;
     *  - an app the ENVIRONMENT owns (the developer's own product, already trusted with the
     *    whole environment) acts in the organization its token names, or — with none, as a
     *    machine token — in the environment's own unowned secrets;
     *  - a token whose app this environment does not know is refused.
     *
     * A refusal is a 403 with no detail. Callers on the lease path turn it into the vault's
     * uniform `lease_denied`.
     *
     * @throws AuthorizationException when the token's app may not act in the vault here
     */
    private function owner(Request $request): ?VaultOwner
    {
        $token = $request->attributes->get('cbox_token');

        if (! $token instanceof Introspection || $token->clientId === null) {
            throw new AuthorizationException('This token may not use the vault.');
        }

        $client = Client::query()->where('client_id', $token->clientId)->first();

        if ($client === null) {
            throw new AuthorizationException('This token may not use the vault.');
        }

        $claimed = $token->claims['org'] ?? null;
        $claimed = is_string($claimed) && $claimed !== '' ? $claimed : null;

        if ($client->organization_id !== null) {
            if ($claimed !== null && $claimed !== $client->organization_id) {
                throw new AuthorizationException('This token may not use the vault.');
            }

            return VaultOwner::organization($client->organization_id);
        }

        return $claimed === null ? null : VaultOwner::organization($claimed);
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
        } catch (LeaseDenied|AuthorizationException) {
            // Uniform on purpose (see the docblock): unknown, revoked, expired and
            // ungranted are one indistinguishable refusal. The `message` is a CONSTANT
            // so carrying the API's standard envelope adds no signal to enumerate with.
            return new JsonResponse(['error' => 'lease_denied', 'message' => 'The lease was denied.'], 403);
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
        // A constant message, for the same no-enumeration reason as `lease_denied`:
        // "no such secret in YOUR organization" and "no such secret at all" must be
        // one response.
        return new JsonResponse(['error' => 'not_found', 'message' => 'No such secret.'], 404);
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireScope;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Pipes\Contracts\PipeTokens;
use Cbox\Id\Pipes\Exceptions\PipeConnectionMissing;
use Cbox\Id\Pipes\Exceptions\PipeLeaseDenied;
use Cbox\Id\Pipes\Exceptions\PipeReauthorizationRequired;
use Cbox\Id\Pipes\Exceptions\PipeRefreshFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Pipes lease: an authorised app asks for a FRESH access token for one person's
 * connected account at one provider — `POST /api/v1/vault/pipes/{provider}/token`, beside
 * the vault's own lease and behind the same `vault.lease` scope.
 *
 * Whose token:
 *  - a token issued to the app FOR a person (authorization code, token exchange) leases
 *    that person's connection, and nobody else's — a `user_id` naming someone else is
 *    refused;
 *  - a machine token (client credentials, whose subject is the app itself) names the
 *    person in `user_id`.
 *
 * Who may: the app must be granted the pipe (Developers › Pipes). An app an ORGANIZATION
 * owns may, in addition, only lease for that organization's members — a tenant's app is
 * not a way to act as another tenant's people. Every refusal for an app that may not lease
 * is the same 403 `lease_denied`, whatever the reason; an app that may is told when the
 * person has not connected (404 `not_connected`) or must connect again (409
 * `reauthorization_required`), because it cannot show the right button otherwise.
 *
 * The token is in the response body and nowhere else: `Cache-Control: no-store`, nothing
 * logged, and the audit entries (`pipe.token.leased`, `vault.secret.leased`) carry the
 * purpose, never the value. Authentication and scope are {@see RequireScope}'s.
 */
final class PipeTokenController extends Controller
{
    public function lease(Request $request, string $provider, PipeTokens $tokens): JsonResponse
    {
        $request->validate([
            'purpose' => ['required', 'string', 'max:200'],
            'user_id' => ['nullable', 'string', 'max:128'],
        ]);

        $token = $request->attributes->get('cbox_token');

        if (! $token instanceof Introspection || $token->clientId === null) {
            return $this->denied();
        }

        $client = Client::query()->where('client_id', $token->clientId)->first();

        if ($client === null) {
            return $this->denied();
        }

        $person = $token->subject !== null && $token->subject !== $token->clientId ? $token->subject : null;
        $asked = $request->filled('user_id') ? $request->string('user_id')->toString() : null;

        if ($person !== null && $asked !== null && ! hash_equals($person, $asked)) {
            return $this->denied();
        }

        $userId = $person ?? $asked;

        if ($userId === null) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'Name the person in user_id — this token is not issued for one.',
            ], 422);
        }

        if ($client->organization_id !== null && ! $this->isMember($client->organization_id, $userId)) {
            return $this->denied();
        }

        try {
            $lease = $tokens->lease($provider, $userId, $token->clientId, $request->string('purpose')->toString());
        } catch (PipeLeaseDenied) {
            return $this->denied();
        } catch (PipeConnectionMissing) {
            return $this->noStore(new JsonResponse([
                'error' => 'not_connected',
                'message' => 'This person has not connected the provider.',
                'connect_url' => route('account.pipes.connect', $provider),
            ], 404));
        } catch (PipeReauthorizationRequired) {
            return $this->noStore(new JsonResponse([
                'error' => 'reauthorization_required',
                'message' => 'This person must connect the provider again.',
                'connect_url' => route('account.pipes.connect', $provider),
            ], 409));
        } catch (PipeRefreshFailed) {
            return new JsonResponse([
                'error' => 'temporarily_unavailable',
                'message' => 'The provider could not refresh the token right now. Try again shortly.',
            ], 503, ['Retry-After' => '5']);
        }

        return $this->noStore(new JsonResponse([
            'access_token' => $lease->accessToken,
            'token_type' => $lease->tokenType,
            'provider' => $lease->provider,
            'user_id' => $lease->userId,
            'connection_id' => $lease->connectionId,
            'scopes' => $lease->scopes,
            'expires_at' => $lease->expiresAt?->format(DATE_ATOM),
            'lease_expires_at' => $lease->leaseExpiresAt->format(DATE_ATOM),
            'metadata' => (object) $lease->metadata,
        ]));
    }

    /**
     * An ACTIVE member of the organization. Memberships are tenant-owned, so the read is
     * pinned to that organization rather than to whatever the request stands in.
     */
    private function isMember(string $organizationId, string $userId): bool
    {
        return (bool) app(TenantContext::class)->runAs(GenericTenant::of($organizationId), static fn (): bool => Membership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', MembershipStatus::Active->value)
            ->exists());
    }

    private function denied(): JsonResponse
    {
        // A CONSTANT, like the vault's own: the envelope must add no signal to enumerate
        // pipes, apps or people with.
        return $this->noStore(new JsonResponse(['error' => 'lease_denied', 'message' => 'The lease was denied.'], 403));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\McpProtectedResources;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\OAuth\Contracts\AuthorizationOrganizations;
use App\Platform\PlaneResolver;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Dpop\DpopResourceGuard;
use Cbox\Id\OAuthServer\Exceptions\InvalidDpopProof;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\ProtectedResource;
use Illuminate\Http\Request;

/**
 * Turns an OAuth access token presented to the management plane into the person it stands
 * for — a {@see DelegatedTokenPrincipal} — or into nothing at all.
 *
 * ONE READING FOR BOTH DOORS. `/mcp` ({@see AuthenticateMcp}) and the REST environment
 * plane ({@see AuthenticateEnvironmentApi}) accept the same token for the same reasons, so
 * the reasons are written once, here, and a door cannot be the one that forgot to ask one.
 *
 * ONE AUDIENCE: THE ENVIRONMENT'S `/mcp`. The management plane is one resource server with
 * two transports — the same actions, scopes and refusals behind JSON-RPC and behind REST —
 * so it is one RFC 8707 audience ({@see McpProtectedResources}), and a person consents once
 * and holds one refresh token for both. Its identifier is the MCP server's URL because that
 * is what an MCP client discovers and must name exactly; a separate "management API"
 * resource would make the `cbox` CLI and Claude Code ask for, and the person approve, two
 * grants for what is one authority.
 *
 * What a token must be, every one of them a refusal (`null`, which the doors answer 401
 * `invalid_token`):
 *
 *  - LIVE: signed by this environment, unexpired, unrevoked, its subject still active
 *    ({@see TokenIntrospector});
 *  - FOR THIS RESOURCE: an `aud` present — the framework treats a token without one as
 *    first-party, which is the one reading this door must not take — naming this
 *    environment's `/mcp`. A token minted for an app's API, or for another environment's
 *    management plane, is somebody else's;
 *  - FROM THIS ISSUER, said by `iss` as well as by the key that signed it;
 *  - A PERSON'S: a subject, and no `act`. A client-credentials token stands for nobody,
 *    and a support session's token is an administrator wearing somebody's identity — the
 *    management plane is reached as yourself or not at all;
 *  - PRESENTED BY ITS HOLDER: a DPoP-bound token with a valid proof for this request
 *    ({@see DpopResourceGuard}), a plain bearer token as it is.
 *
 * Whether the person may do any particular thing is NOT asked here: a valid token whose
 * person holds no rights is still a valid credential, and the answer to it is an empty
 * tool list and a `whoami` that says why — insufficient scope is a per-action question.
 */
final readonly class DelegatedAccess
{
    public function __construct(
        private TokenIntrospector $tokens,
        private DpopResourceGuard $dpop,
        private ProtectedResources $resources,
        private IssuerResolver $issuers,
        private EnvironmentContext $environments,
        private AuthorizationOrganizations $organizations,
        private PlaneResolver $planes,
        private Subjects $subjects,
        private ClientRegistry $clients,
    ) {}

    /** The management plane's resource in this environment — the audience a token must name. */
    public function resource(): ?ProtectedResource
    {
        return $this->resources->forMetadataPath(ProtectedResource::WELL_KNOWN.McpProtectedResources::PATH);
    }

    /**
     * The person the request's access token stands for, or null when it stands for nobody
     * this door accepts.
     */
    public function principal(Request $request): ?DelegatedTokenPrincipal
    {
        $token = $this->dpop->bearer($request);
        $resource = $this->resource();
        $environmentId = $this->environments->current()?->environmentKey();

        if ($token === null || $resource === null || ! is_string($environmentId) || $environmentId === '') {
            return null;
        }

        $introspection = $this->tokens->introspect($token);

        if (! $this->vouchesFor($introspection, $resource) || $introspection->subject === null || $introspection->clientId === null) {
            return null;
        }

        try {
            $this->dpop->enforce($request, $token, $introspection);
        } catch (InvalidDpopProof) {
            return null;
        }

        $subjectId = $introspection->subject;
        $clientId = $introspection->clientId;
        $organizationId = $introspection->claims['org'] ?? null;
        $subject = $this->subjects->find($subjectId);
        $expiresAt = $introspection->claims['exp'] ?? null;

        return new DelegatedTokenPrincipal(
            subjectId: $subjectId,
            personName: $subject->name ?? $subject->email ?? $subjectId,
            clientId: $clientId,
            clientName: $this->clientName($clientId),
            environmentId: $environmentId,
            scopes: $introspection->scopes,
            // Asked NOW, not read off the token: `org_role` is what the role was when the
            // token was minted, and a person removed or demoted since keeps neither.
            organization: is_string($organizationId) && $organizationId !== ''
                ? $this->organizations->usableBy($subjectId, $organizationId)
                : null,
            customerConsole: $this->planes->onCustomerEnvironment(),
            expiresAt: is_int($expiresAt) ? $expiresAt : null,
        );
    }

    private function vouchesFor(Introspection $introspection, ProtectedResource $resource): bool
    {
        if (! $introspection->active || $introspection->actorSubject() !== null) {
            return false;
        }

        $audience = $introspection->claims['aud'] ?? null;
        $issuer = $introspection->claims['iss'] ?? null;

        return $audience !== null && $audience !== '' && $audience !== []
            && $introspection->isAudience($resource->identifier)
            && is_string($issuer)
            && hash_equals(rtrim($this->issuers->issuer(), '/'), rtrim($issuer, '/'));
    }

    /**
     * What the person knows the client as: its registered name, or the host of the
     * metadata document that describes it — the one verified fact about such a client.
     * Looked up in the environment being served; {@see RootDelegatedAccess} asks it inside
     * the platform root, where its clients are registered.
     */
    public function clientName(string $clientId): string
    {
        $registered = $this->clients->byClientId($clientId);

        if ($registered !== null) {
            return $registered->name;
        }

        $host = parse_url($clientId, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $clientId;
    }
}

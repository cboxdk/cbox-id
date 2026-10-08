<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\McpProtectedResources;
use App\Platform\Actions\Principal\RootOperatorPrincipal;
use App\Platform\Actions\Principal\RootPersonPrincipal;
use App\Platform\Console\ConsoleScope;
use App\Platform\OAuth\ValueObjects\RootSignIn;
use App\Platform\OAuth\ValueObjects\RootWorkspace;
use App\Platform\PlaneResolver;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Dpop\DpopResourceGuard;
use Cbox\Id\OAuthServer\Exceptions\InvalidDpopProof;
use Cbox\Id\OAuthServer\ValueObjects\ProtectedResource;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;

/**
 * Turns an access token the PLATFORM ROOT issued into the person it stands for — a
 * workspace member, an operator, or both — or into nothing at all.
 *
 * WHY THE ROOT NEEDS ITS OWN READING. {@see DelegatedAccess} reads a token an
 * environment's issuer minted for one of that environment's own subjects, which is the
 * organization console's person. The people who ADMINISTER environments on a hosted
 * deployment are not those subjects: they are the workspace's team — subjects of the
 * platform root, who reach a tenant's console through the signed handoff and are never a
 * tenant's users. No tenant issuer mints a token for them, so until now the only thing
 * they could hand an agent was a management key. Their token is the root's, audienced to
 * the root host's own `/mcp`, and it is read here.
 *
 * THE SAME FIVE REFUSALS, asked of the root rather than of whichever environment the
 * request's host resolved to — the workspace and platform planes resolve none — so every
 * lookup runs inside the platform root ({@see PlatformRoot::run()}):
 *
 *  - LIVE: signed by the root, unexpired, unrevoked, its subject still active;
 *  - FOR THE ROOT'S `/mcp`: an `aud` present and naming it ({@see McpProtectedResources}).
 *    A token an environment issued — even to a person who happens to exist in both — is
 *    somebody else's audience and never reads as the root's;
 *  - FROM THE ROOT'S ISSUER, said by `iss` as well as by the key that signed it;
 *  - A PERSON'S: a subject, and no `act` — a support session's token is an administrator
 *    wearing somebody's identity, and the management planes are reached as yourself;
 *  - PRESENTED BY ITS HOLDER: a DPoP-bound token with a valid proof for this request.
 *
 * WHAT THE PERSON HOLDS is read NOW, never from the token ({@see RootWorkspace}): the
 * workspace from an active membership of a live organization that owns products — the one
 * the token was bound to (`org`) when that still holds, else the person's first — and the
 * operator record behind the subject, which the framework lookup refuses once suspended.
 * A token whose person holds nothing is still a valid credential: its answer is an empty
 * tool list and a `whoami` that says why, exactly as {@see DelegatedAccess} has it.
 */
final readonly class RootDelegatedAccess
{
    public function __construct(
        private TokenIntrospector $tokens,
        private DpopResourceGuard $dpop,
        private ProtectedResources $resources,
        private IssuerResolver $issuers,
        private PlatformRoot $root,
        private EnvironmentContext $environments,
        private PlaneResolver $planes,
        private Subjects $subjects,
        private Memberships $memberships,
        private Organizations $organizations,
        private OrganizationProjects $projects,
        private PlatformOperators $operators,
        private DelegatedAccess $delegated,
    ) {}

    /**
     * Whether this request is on the root host of a MULTI-TENANT deployment — the one host
     * whose `/mcp` and environment API speak for the workspace rather than for one
     * environment. On a single-tenant install the root IS the product's environment, its
     * people are its subjects, and {@see DelegatedAccess} keeps answering there exactly as
     * it did.
     */
    public function onRootHost(): bool
    {
        return $this->planes->onAccountPlane();
    }

    /** Whether the environment this request resolved to — if any — is the platform root. */
    public function inRootContext(): bool
    {
        $current = $this->environments->current()?->environmentKey();

        return $current === null || $current === $this->root->environment()?->environmentKey();
    }

    /** The root's `/mcp` resource — the audience a root token must name. */
    public function resource(): ?ProtectedResource
    {
        return $this->root->run(
            fn (): ?ProtectedResource => $this->resources->forMetadataPath(ProtectedResource::WELL_KNOWN.McpProtectedResources::PATH),
        );
    }

    /**
     * The person the request's root token stands for — an {@see RootOperatorPrincipal} when
     * they run the deployment — or null when the bearer is no root token this door accepts.
     */
    public function principal(Request $request): ?RootPersonPrincipal
    {
        $signIn = $this->signIn($request);

        if ($signIn === null) {
            return null;
        }

        $workspace = $this->workspaceOf($signIn->subjectId, $signIn->organizationId);
        $operatorId = $this->operatorIdOf($signIn->subjectId);

        return $operatorId !== null
            ? new RootOperatorPrincipal($signIn, $workspace, $operatorId)
            : new RootPersonPrincipal($signIn, $workspace);
    }

    /** The facts of a valid root token, or null. */
    public function signIn(Request $request): ?RootSignIn
    {
        $token = $this->dpop->bearer($request);

        // A management key is told apart by its prefix and is never introspected as a token.
        if ($token === null || $token === '' || str_starts_with($token, AuthenticateMcp::KEY_PREFIX)) {
            return null;
        }

        return $this->root->run(function () use ($token, $request): ?RootSignIn {
            $resource = $this->resources->forMetadataPath(ProtectedResource::WELL_KNOWN.McpProtectedResources::PATH);

            if ($resource === null) {
                return null;
            }

            $introspection = $this->tokens->introspect($token);

            if (! $introspection->active || $introspection->actorSubject() !== null || $introspection->subject === null || $introspection->clientId === null) {
                return null;
            }

            $audience = $introspection->claims['aud'] ?? null;
            $issuer = $introspection->claims['iss'] ?? null;

            if ($audience === null || $audience === '' || $audience === [] || ! $introspection->isAudience($resource->identifier)
                || ! is_string($issuer) || ! hash_equals(rtrim($this->issuers->issuer(), '/'), rtrim($issuer, '/'))) {
                return null;
            }

            try {
                $this->dpop->enforce($request, $token, $introspection);
            } catch (InvalidDpopProof) {
                return null;
            }

            $subject = $this->subjects->find($introspection->subject);
            $organizationId = $introspection->claims['org'] ?? null;
            $expiresAt = $introspection->claims['exp'] ?? null;

            return new RootSignIn(
                subjectId: $introspection->subject,
                personName: $subject->name ?? $subject->email ?? $introspection->subject,
                clientId: $introspection->clientId,
                clientName: $this->delegated->clientName($introspection->clientId),
                scopes: $introspection->scopes,
                organizationId: is_string($organizationId) && $organizationId !== '' ? $organizationId : null,
                expiresAt: is_int($expiresAt) ? $expiresAt : null,
                token: $introspection,
            );
        });
    }

    /**
     * Whether a subject of the platform root holds anything a root token could reach — a
     * workspace's team or an operator — asked NOW.
     *
     * The device grant never asked: a CLI token for somebody who holds nothing is still a
     * valid credential, answered with an empty tool list and a `whoami` that says why. An
     * MCP client signing a person in through `/oauth/authorize` is asked here instead, before
     * the person is shown a consent screen for a connection that could do nothing
     * ({@see RootMcpOAuth::admits()}): the root is nobody else's sign-in.
     */
    public function holdsStanding(string $subjectId): bool
    {
        return $this->workspaceOf($subjectId) !== null || $this->operatorIdOf($subjectId) !== null;
    }

    /** The operator record behind a root subject — refused by the lookup once suspended. */
    private function operatorIdOf(string $subjectId): ?string
    {
        $operatorId = $this->root->run(fn (): ?string => $this->operators->findBySubject($subjectId)?->id);

        return is_string($operatorId) && $operatorId !== '' ? $operatorId : null;
    }

    /**
     * The workspace the person is on the team of, asked now: the one the token was bound
     * to when that membership still stands, else their first — always an ACTIVE membership
     * of a LIVE organization that owns products, which is what makes an organization a
     * workspace rather than somebody's tenant ({@see ConsoleScope::membershipRole()}).
     */
    public function workspaceOf(string $subjectId, ?string $preferred = null): ?RootWorkspace
    {
        return $this->root->run(function () use ($subjectId, $preferred): ?RootWorkspace {
            $memberships = $this->memberships->forUser($subjectId)
                ->filter(static fn (Membership $membership): bool => $membership->status === MembershipStatus::Active)
                ->sortBy(static fn (Membership $membership): int => $membership->organization_id === $preferred ? 0 : 1)
                ->values();

            foreach ($memberships as $membership) {
                $organization = $this->organizations->find($membership->organization_id);

                if ($organization === null || $organization->status->revokesAccess()
                    || $this->projects->forOrganization($organization->id)->isEmpty()) {
                    continue;
                }

                return new RootWorkspace(
                    id: $organization->id,
                    name: $organization->name,
                    role: $membership->role,
                    allEnvironments: $membership->all_environments,
                    environmentIds: $this->memberships->accessibleEnvironmentIds($organization->id, $subjectId),
                );
            }

            return null;
        });
    }
}

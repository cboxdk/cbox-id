<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Actions\Workspace\InWorkspace;
use App\Mcp\ActionTool;
use App\Platform\Actions\AccountScopes;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\Danger;
use App\Platform\Actions\PlatformScopes;
use App\Platform\Actions\WorkspaceScopes;
use App\Platform\OAuth\RootDelegatedAccess;
use App\Platform\OAuth\ValueObjects\RootSignIn;
use App\Platform\OAuth\ValueObjects\RootWorkspace;
use App\Platform\OrganizationCapabilities;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Cbox\Id\Platform\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A PERSON OF THE PLATFORM ROOT acting through an agent or a CLI they signed in — a token
 * the root issued for its own `/mcp` ({@see RootDelegatedAccess} reads and vouches for it).
 * The people who administer a hosted deployment's environments are these: a workspace's
 * team, and the operators running the deployment. One connection, for everything the
 * person could do from their own console.
 *
 * EVERY PLANE ASKS TWO QUESTIONS, the same two {@see DelegatedTokenPrincipal} asks: the
 * token must carry the action's scope — what the person agreed to hand the client — AND the
 * person must hold the right themself, asked the way their console asks it. A scope never
 * adds a right; a right is never handed to a client that was not given its scope.
 *
 *  - THE WORKSPACE PLANE, as a MEMBER of the team: the role on the membership must hold the
 *    capability the scope needs and the capability the action's gate names
 *    ({@see WorkspaceScopes}, {@see OrganizationCapabilities}) — the answer
 *    {@see WorkspaceKeyPrincipal} gives a key holding that role, and the workspace
 *    console gives the person. The trail names the member ({@see InWorkspace::actor()}).
 *  - AN ENVIRONMENT'S PLANE, in one environment at a time, named per call
 *    ({@see inEnvironment()}): the person may act there when they could open its console —
 *    the role administers environments and the membership reaches that one, the two
 *    checks `/open/{environment}` makes — and then holds that console's rights. That is a
 *    different principal ({@see EnvironmentMemberPrincipal}), bound to the environment and
 *    run inside its tenancy; THIS one refuses every environment action outright, so a door
 *    that forgot to bind could only ever fail closed. {@see reachesEnvironmentAction()} is
 *    the listing question.
 *  - THE PERSON'S OWN ACCOUNT on the root: the `account:*` scope, and nothing else to ask —
 *    every account action is keyed to {@see subjectId()}.
 *  - THE PLATFORM PLANE is an operator's, and refused here ({@see RootOperatorPrincipal}).
 *
 * CRITICAL ALWAYS WAITS FOR THE PERSON, as for any token: an agent holding it may be acting
 * on a prompt nobody read. The approval is filed with the person in the platform root,
 * where their devices are enrolled.
 */
readonly class RootPersonPrincipal implements PersonPrincipal, SignedInPerson
{
    public function __construct(
        protected RootSignIn $signIn,
        protected ?RootWorkspace $workspace,
    ) {}

    /**
     * One kind for the person's every principal — this one and the environment-bound one
     * {@see inEnvironment()} returns — so the approvals the second raises are found by the
     * first ({@see self::owns()}).
     */
    public function kind(): string
    {
        return 'person';
    }

    public function id(): string
    {
        return $this->signIn->principalId();
    }

    /** A user of the platform root — the console's own answer for a person signed in there. */
    public function auditActor(): AuditActor
    {
        return AuditActor::user($this->signIn->subjectId);
    }

    public function authorize(ActionDefinition $action): void
    {
        match ($action->plane) {
            ActionPlane::Account => $this->assertGranted(AccountScopes::knows($action->scope), $action->scope),
            ActionPlane::Workspace => $this->assertWorkspaceMember($action),
            ActionPlane::Environment => throw new AuthorizationException('Name the environment to act in: an environment action runs in one environment of your workspace at a time.'),
            ActionPlane::Platform => throw new AuthorizationException('Only a platform operator can do this.'),
        };
    }

    /**
     * Whether an environment action is one this person could run in SOME environment of
     * their workspace — what decides whether its tool is listed ({@see ActionTool::permits()}).
     * Which environment is a per-call question, answered by {@see inEnvironment()}.
     */
    public function reachesEnvironmentAction(ActionDefinition $action): bool
    {
        return $action->plane === ActionPlane::Environment
            && $this->workspace?->administersEnvironments() === true
            && EnvironmentMemberPrincipal::consoleRuns($action)
            && app(ManagementScopes::class)->knows($action->scope)
            && $this->signIn->grants($action->scope);
    }

    /**
     * This person, bound to one environment of their workspace — by id or by slug — or a
     * refusal. The `/open/{environment}` checks, in its order: the role must administer
     * environments (403), and the environment must be one of the workspace's that the
     * membership reaches. Anything else — another workspace's, one they were never given, a
     * name that matches nothing — is not found, the same answer the workspace console gives
     * for an environment it never showed them.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public function inEnvironment(string $reference): EnvironmentMemberPrincipal
    {
        $workspace = $this->workspace ?? throw new AuthorizationException('You are not on a workspace\'s team, so there is no environment to act in.');

        if (! $workspace->capabilities()->canManageEnvironments()) {
            throw new AuthorizationException('Your role in this workspace does not administer environments.');
        }

        $reference = trim($reference);

        $environment = $reference === '' || $workspace->environmentIds === [] ? null : app(EnvironmentContext::class)->withoutScope(
            static fn (): ?Environment => Environment::query()
                ->where(static fn ($query) => $query->whereKey($reference)->orWhere('slug', $reference))
                ->whereIn('id', $workspace->environmentIds)
                ->whereIn('project_id', Project::query()->where('organization_id', $workspace->id)->select('id'))
                ->first(),
        );

        if (! $environment instanceof Environment) {
            throw ActionRefused::notFound('environment');
        }

        return new EnvironmentMemberPrincipal($this->signIn, $environment, $workspace);
    }

    /**
     * The environments this person may act in — what `whoami` lists and an agent picks the
     * `environment` argument from. Empty for a role that does not administer environments.
     *
     * @return list<Environment>
     */
    public function environments(): array
    {
        $workspace = $this->workspace;

        if ($workspace === null || ! $workspace->administersEnvironments()) {
            return [];
        }

        return array_values(app(EnvironmentContext::class)->withoutScope(
            static fn (): array => Environment::query()
                ->whereIn('id', $workspace->environmentIds)
                ->whereIn('project_id', Project::query()->where('organization_id', $workspace->id)->select('id'))
                ->orderBy('name')
                ->get(['id', 'slug', 'name', 'type'])
                ->all(),
        ));
    }

    public function supportsIdempotency(): bool
    {
        return true;
    }

    public function label(): string
    {
        return '"'.$this->signIn->clientName.'" for '.$this->signIn->personName;
    }

    /** Every critical action, whatever else: the product's floor for a token, not a choice. */
    public function stepUpPolicy(): StepUpPolicy
    {
        return new StepUpPolicy(Danger::Critical);
    }

    public function approverSubjectId(): string
    {
        return $this->signIn->subjectId;
    }

    /** The person is a subject of the platform root, where their devices are enrolled. */
    public function approverEnvironmentId(): ?string
    {
        return null;
    }

    /**
     * Nothing to be confined to, because nothing here is an organization of an environment:
     * the workspace plane fences every id by the workspace ({@see InWorkspace}), the account
     * plane by the person, and every environment action is refused until the person is bound
     * to an environment by {@see inEnvironment()}.
     */
    public function confinedToOrganization(): ?string
    {
        return null;
    }

    public function subjectId(): string
    {
        return $this->signIn->subjectId;
    }

    public function personName(): string
    {
        return $this->signIn->personName;
    }

    public function clientId(): string
    {
        return $this->signIn->clientId;
    }

    public function clientName(): string
    {
        return $this->signIn->clientName;
    }

    /**
     * Every scope the token carries that one of the planes defines.
     *
     * @return list<string>
     */
    public function managementScopes(): array
    {
        $environment = app(ManagementScopes::class);

        return array_values(array_filter($this->signIn->scopes, static fn (string $scope): bool => $environment->knows($scope)
            || WorkspaceScopes::knows($scope)
            || AccountScopes::knows($scope)
            || PlatformScopes::knows($scope)));
    }

    public function expiresAt(): ?int
    {
        return $this->signIn->expiresAt;
    }

    /** A token is no sign-in session: "everywhere else" is everywhere. */
    public function currentSessionId(): ?string
    {
        return null;
    }

    public function grants(string $scope): bool
    {
        return $this->signIn->grants($scope);
    }

    public function signIn(): RootSignIn
    {
        return $this->signIn;
    }

    /** The workspace the person is on the team of, as it stands now — null for nobody's. */
    public function workspace(): ?RootWorkspace
    {
        return $this->workspace;
    }

    public function isOperator(): bool
    {
        return false;
    }

    /**
     * Whether an approval or an idempotent answer recorded under $owner (`kind:id`) is this
     * person's through this client: their own, or one the environment-bound principal
     * {@see inEnvironment()} returns raised — whose id is this one's, plus the environment.
     */
    public function owns(string $owner): bool
    {
        $mine = $this->kind().':'.$this->id();

        return $owner === $mine || str_starts_with($owner, $mine.':');
    }

    /**
     * The workspace plane, as the member: the action's scope needs a capability the role
     * holds, so does its gate, and the token carries the scope.
     *
     * @throws AuthorizationException
     */
    private function assertWorkspaceMember(ActionDefinition $action): void
    {
        $workspace = $this->workspace ?? throw new AuthorizationException('You are not on a workspace\'s team.');
        $can = $workspace->capabilities();

        foreach ([WorkspaceScopes::capability($action->scope), $action->consoleGate->capability()] as $capability) {
            if ($capability === false || ! WorkspaceScopes::holds($can, $capability)) {
                throw new AuthorizationException('Your role in this workspace may not '.($capability === false ? 'run this' : $capability).'.');
            }
        }

        $this->assertGranted(WorkspaceScopes::knows($action->scope), $action->scope);
    }

    /** @throws AuthorizationException */
    protected function assertGranted(bool $known, string $scope): void
    {
        if (! $known || ! $this->signIn->grants($scope)) {
            throw new AuthorizationException("This sign-in was not granted the required scope: {$scope}.");
        }
    }
}

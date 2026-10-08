<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Http\Controllers\EnvironmentHandoffController;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Console\ConsoleScope;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\OAuth\ValueObjects\RootSignIn;
use App\Platform\OAuth\ValueObjects\RootWorkspace;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A workspace member's root-host token, BOUND TO ONE ENVIRONMENT of their workspace for one
 * call — what {@see RootPersonPrincipal::inEnvironment()} returns once the person has
 * passed the two checks `/open/{environment}` makes ({@see EnvironmentHandoffController}):
 * their role administers environments, and their membership reaches this one.
 *
 * THE ENVIRONMENT CONSOLE'S RIGHTS, within the token's scopes. Opening that console is
 * what the person could do with their own browser, and on it they hold the environment —
 * every organization in it, and the things the environment owns that no organization has a
 * share of ({@see ConsoleScope::assertMayAdministerEnvironment()}). So an action whose gate
 * is {@see ConsoleGate::EnvironmentAdmin} or {@see ConsoleGate::Administer} runs, confined
 * to no organization, once the token carries its scope. Nothing else in this environment
 * is theirs to run, and no other environment is reachable through this principal at all:
 * the door runs the action inside THIS environment's tenancy
 * ({@see EnvironmentContext::runAs()}), so every lookup it makes is fenced to it.
 *
 * THE TRAIL NAMES THE MEMBER — one of the workspace's people acting above every
 * organization in the environment, the actor the environment console records
 * ({@see ConsoleScope::auditActor()}) — in this environment's own trail, with the client
 * they used on every entry.
 *
 * CRITICAL WAITS FOR THE PERSON, approved on their device in the platform root, as for
 * every token.
 */
final readonly class EnvironmentMemberPrincipal implements SignedInPerson, TokenAuthenticated
{
    public function __construct(
        private RootSignIn $signIn,
        private Environment $environment,
        private RootWorkspace $workspace,
    ) {}

    /** The root-issued token this person signed in with ({@see TokenAuthenticated}). */
    public function token(): ?Introspection
    {
        return $this->signIn->token;
    }

    /** Whether the environment console runs $action at all: its two gates, on its plane. */
    public static function consoleRuns(ActionDefinition $action): bool
    {
        return $action->plane === ActionPlane::Environment
            && ($action->consoleGate === ConsoleGate::EnvironmentAdmin || $action->consoleGate === ConsoleGate::Administer);
    }

    /** The person's own kind ({@see RootPersonPrincipal::kind()}), so their approvals are found from either. */
    public function kind(): string
    {
        return 'person';
    }

    /**
     * The person and the client, as for the unbound principal, plus the environment: an
     * idempotency key reused in a second environment is a second request, never a replay
     * of the first environment's answer.
     */
    public function id(): string
    {
        return $this->signIn->principalId().':'.substr(hash('sha256', (string) $this->environment->id), 0, 16);
    }

    public function auditActor(): AuditActor
    {
        return AuditActor::organizationMember($this->signIn->subjectId);
    }

    public function authorize(ActionDefinition $action): void
    {
        if ($action->plane !== ActionPlane::Environment) {
            throw new AuthorizationException('Bound to an environment, this sign-in runs that environment\'s actions only.');
        }

        if (! app(ManagementScopes::class)->knows($action->scope) || ! $this->signIn->grants($action->scope)) {
            throw new AuthorizationException("This sign-in was not granted the required scope: {$action->scope}.");
        }

        // Both the workspace's answer and the environment's own, asked again here so an
        // action reached any other way than the door that bound this principal still asks
        // them: the role administers environments and the membership reaches this one
        // ({@see EnvironmentAdminAuth}'s per-request re-check, for a token).
        if (! self::consoleRuns($action)
            || ! $this->workspace->capabilities()->canManageEnvironments()
            || ! in_array($this->environment->id, $this->workspace->environmentIds, true)) {
            throw new AuthorizationException('You do not have permission to change this.');
        }
    }

    public function supportsIdempotency(): bool
    {
        return true;
    }

    public function label(): string
    {
        return '"'.$this->signIn->clientName.'" for '.$this->signIn->personName.' in '.$this->environment->name;
    }

    public function stepUpPolicy(): StepUpPolicy
    {
        return new StepUpPolicy(Danger::Critical);
    }

    public function approverSubjectId(): string
    {
        return $this->signIn->subjectId;
    }

    /** A member of the workspace is a subject of the platform root. */
    public function approverEnvironmentId(): ?string
    {
        return null;
    }

    /** The environment console's authority: the whole environment, above every organization in it. */
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

    /** @return list<string> */
    public function managementScopes(): array
    {
        $vocabulary = app(ManagementScopes::class);

        return array_values(array_filter($this->signIn->scopes, static fn (string $scope): bool => $vocabulary->knows($scope)));
    }

    public function expiresAt(): ?int
    {
        return $this->signIn->expiresAt;
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function environmentId(): string
    {
        return (string) $this->environment->id;
    }

    public function workspaceId(): string
    {
        return $this->workspace->id;
    }

    /**
     * Run $callback inside this environment's tenancy — where every action this principal
     * runs must run, so that its lookups, its writes and its audit entries are this
     * environment's and no other's.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function within(Closure $callback): mixed
    {
        return app(EnvironmentContext::class)->runAs($this->environment, $callback);
    }
}

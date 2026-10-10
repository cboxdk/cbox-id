<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\WorkspaceScopes;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A person signed in to the console. Their authority is {@see ConsoleScope}'s — the same
 * gates every console page asks — so moving a page's write into an action changes nothing
 * about who may make it.
 */
final readonly class ConsoleSessionPrincipal implements Principal
{
    /**
     * @param  bool  $forEnvironment  acting on the ENVIRONMENT's own settings from a console
     *                                whose administrator administers it — see
     *                                {@see self::forEnvironment()}
     */
    public function __construct(
        private ConsoleScope $scope,
        private bool $forEnvironment = false,
    ) {}

    /**
     * The same person, acting on the environment's own settings: confined to no
     * organization, and holding the environment-administrator gate.
     *
     * Only for a scope that {@see ConsoleScope::administersEnvironment()} — the environment
     * console, or the organization console of a single-tenant install — and refused for any
     * other, so a controller cannot widen a customer's console by asking for this.
     *
     * @throws AuthorizationException
     */
    public static function forEnvironment(ConsoleScope $scope): self
    {
        $scope->assertAdministersEnvironment();

        return new self($scope, forEnvironment: true);
    }

    public function kind(): string
    {
        return 'console';
    }

    public function id(): string
    {
        return $this->scope->actorId();
    }

    public function auditActor(): AuditActor
    {
        return $this->scope->auditActor();
    }

    public function authorize(ActionDefinition $action): void
    {
        match ($action->consoleGate) {
            ConsoleGate::EnvironmentAdmin => $this->forEnvironment
                ? $this->scope->assertAdministersEnvironment()
                : $this->scope->assertMayAdministerEnvironment(),
            ConsoleGate::Administer => $this->scope->assertMayAdminister(),
            ConsoleGate::Operator => $this->assertOperator(),
            ConsoleGate::Person => $this->assertPerson(),
            default => $this->assertWorkspaceCapability($action->consoleGate),
        };
    }

    /**
     * The platform pages' own question, asked of the session that already exists: does the
     * person signed in run this deployment ({@see ConsoleScope::operator()} — an ACTIVE
     * operator record behind their subject). The pages 404 a stranger before an action is
     * reached; this is the refusal for any door that skipped that.
     *
     * @throws AuthorizationException
     */
    private function assertOperator(): void
    {
        if (! $this->scope->isPlatformOperator()) {
            throw new AuthorizationException('Only a platform operator can do this.');
        }
    }

    /**
     * Somebody is signed in. The account actions ask nothing more, because they are keyed
     * to that person and take no id of an account to act on.
     *
     * @throws AuthorizationException
     */
    private function assertPerson(): void
    {
        if (! app(CurrentUser::class)->check()) {
            throw new AuthorizationException('Sign in to change your account.');
        }
    }

    public function supportsIdempotency(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'You';
    }

    /** The console has its own step-up — re-entering the password (sudo) — before a sensitive change. */
    public function stepUpPolicy(): ?StepUpPolicy
    {
        return null;
    }

    public function approverSubjectId(): ?string
    {
        return null;
    }

    public function approverEnvironmentId(): ?string
    {
        return null;
    }

    /**
     * The organization console's own organization — read from the session, never from input
     * — and nothing on the environment console, whose administrator holds the environment.
     */
    public function confinedToOrganization(): ?string
    {
        return $this->scope->plane() === ConsolePlane::Organization && ! $this->forEnvironment
            ? $this->scope->requireOrganizationId()
            : null;
    }

    public function scope(): ConsoleScope
    {
        return $this->scope;
    }

    /**
     * The workspace gates, asked exactly as the workspace console's pages ask them:
     * `capabilities()` is null for anybody administering an organization that is not their
     * own customer workspace, so a person looking at somebody else's organization holds
     * none of them.
     *
     * @throws AuthorizationException
     */
    private function assertWorkspaceCapability(ConsoleGate $gate): void
    {
        $capabilities = $this->scope->capabilities();
        $capability = $gate->capability();

        if ($capabilities === null || $capability === false || ! WorkspaceScopes::holds($capabilities, $capability)) {
            throw new AuthorizationException('You do not have permission to do this in this workspace.');
        }
    }
}

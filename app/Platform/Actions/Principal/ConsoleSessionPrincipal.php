<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\OrganizationScopes;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\CurrentUser;
use App\Platform\Operators\OperatorGate;
use App\Platform\ServiceAccess\ProductAdmission;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A person signed in to the console. Their authority is {@see ConsoleScope}'s — the same
 * gates every console page asks — so moving a page's write into an action changes nothing
 * about who may make it.
 */
final readonly class ConsoleSessionPrincipal implements ConfirmsFreshly, Principal
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
            default => $this->assertOrganizationCapability($action),
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

    /**
     * The console has its own step-up — the plane's fresh confirmation (a password, a
     * passkey, the Authenticator) — rather than a held approval.
     */
    public function stepUpPolicy(): ?StepUpPolicy
    {
        return null;
    }

    /**
     * EVERY CRITICAL RUN FROM A CONSOLE WAITS FOR THE PLANE'S STEP-UP — asked here, by the
     * runner, rather than by each route.
     *
     * The step-up used to be opt-in per route: `sudo` on some, `env.sudo` on others, a
     * controller's own challenge on a few. The routes that forgot were exactly the ones a
     * borrowed or hijacked console session wants — repointing an SSO connection, turning
     * MFA off, granting a staff role, transferring an organization, changing a person's
     * address before a password reset, minting an Admin Portal link, registering a SCIM
     * target. {@see Danger::Critical} already says "mints credentials or changes how people
     * sign in"; the console now treats it as meaning "confirm it's you first", so a new
     * route cannot forget. The window is the plane's (fifteen minutes), so a person doing
     * several such things is asked once; a route that already asked finds it open.
     *
     * Not here: the operator plane's actions, which answer to {@see OperatorGate} before
     * they are run; and an action whose page decided otherwise and says why
     * (`consoleStepUp: false`). A person's own account actions confirm on the account
     * plane ({@see Sudo}), as their `sudo` routes do.
     */
    public function confirmFresh(ActionDefinition $action): void
    {
        if ($action->danger !== Danger::Critical || ! $action->consoleStepUp || $action->consoleGate === ConsoleGate::Operator) {
            return;
        }

        app(ConsoleStepUp::class)->demand(
            StepUpReason::forRoute(request()->route()?->getName()) ?? __('console.this_is_a_critical_change_action_7d3f19a2', ['action' => $action->summary]),
            personal: $action->consoleGate === ConsoleGate::Person,
        );
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
    private function assertOrganizationCapability(ActionDefinition $action): void
    {
        $capabilities = $this->scope->capabilities();
        $capability = $action->consoleGate->capability();

        if ($capabilities === null || $capability === false || ! OrganizationScopes::holds($capabilities, $capability)) {
            throw new AuthorizationException('You do not have permission to do this in this organization.');
        }

        // The product's admission, as the organization key and the root sign-in ask it for
        // the same scopes: the console is one more door to the same actions, not a way
        // round Billing's answer (minting a management key, claiming a custom domain).
        if (OrganizationScopes::admitted($action->scope)) {
            app(ProductAdmission::class)->assertGranted($this->scope->actorId(), $this->scope->requireOrganizationId(), acting: $action->danger->writes());
        }
    }
}

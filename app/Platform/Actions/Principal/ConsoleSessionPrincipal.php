<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\WorkspaceScopes;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A person signed in to the console. Their authority is {@see ConsoleScope}'s — the same
 * gates every console page asks — so moving a page's write into an action changes nothing
 * about who may make it.
 */
final readonly class ConsoleSessionPrincipal implements Principal
{
    public function __construct(private ConsoleScope $scope) {}

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
            ConsoleGate::EnvironmentAdmin => $this->scope->assertMayAdministerEnvironment(),
            ConsoleGate::Administer => $this->scope->assertMayAdminister(),
            default => $this->assertWorkspaceCapability($action->consoleGate),
        };
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

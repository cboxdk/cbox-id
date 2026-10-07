<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;

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
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\Principal\PersonPrincipal;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Illuminate\Auth\Access\AuthorizationException;

/** A person's delegated token: their own account, and the scopes they gave it. */
readonly class FakePersonToken implements PersonPrincipal
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        protected string $subjectId,
        protected array $scopes,
        protected ?string $sessionId = null,
    ) {}

    public function kind(): string
    {
        return 'delegated_token';
    }

    public function id(): string
    {
        return $this->subjectId;
    }

    public function auditActor(): AuditActor
    {
        return AuditActor::user($this->subjectId);
    }

    public function authorize(ActionDefinition $action): void
    {
        if (! in_array($action->plane, $this->planes(), true) || ! $this->grants($action->scope)) {
            throw new AuthorizationException("This token is missing the required scope: {$action->scope}.");
        }
    }

    public function supportsIdempotency(): bool
    {
        return true;
    }

    public function label(): string
    {
        return 'Delegated token';
    }

    public function stepUpPolicy(): ?StepUpPolicy
    {
        return null;
    }

    public function approverSubjectId(): ?string
    {
        return null;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function currentSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function grants(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** @return list<ActionPlane> */
    protected function planes(): array
    {
        return [ActionPlane::Account];
    }
}

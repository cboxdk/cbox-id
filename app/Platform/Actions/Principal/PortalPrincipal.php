<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\OrganizationTarget;
use App\Platform\AdminPortal;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use App\Platform\Integrations\IntegrationReach;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A CUSTOMER'S IT ADMINISTRATOR IN THE ADMIN PORTAL — somebody with no account here at all,
 * holding a session a single-use link opened ({@see AdminPortal::redeem()}).
 *
 * Two confinements, and both are this class's to state rather than each portal page's to
 * remember:
 *
 *  - ONE ORGANIZATION. {@see confinedToOrganization()} answers the link's, so every action
 *    that reaches into organizations — {@see OrganizationTarget},
 *    {@see IntegrationReach}, the SSO lookups — refuses any
 *    other, and refuses "the environment's own" as the forged request it would be.
 *  - ITS INTENTS. {@see authorize()} allows exactly the actions the link's
 *    {@see PortalIntent}s list ({@see PortalIntent::actions()}), and only while the
 *    organization's plan still includes the intent that lists it. Not a scope string a
 *    key could hold, not a console gate: a portal session opened for directory sync cannot
 *    claim a domain by forming the request, whatever it sends.
 *
 * THE TRAIL. The person is a stranger to this platform, so the trail names the SESSION:
 * the system, with the link's id as the actor id, and the link's minter and the door
 * (`via: portal`) in the context ({@see trailContext()}). "Who let them in" is answerable
 * from every entry they caused; "who they were" never was.
 */
final readonly class PortalPrincipal implements AnnotatesTrail, Principal
{
    /** The context key naming the link that opened the session. */
    public const string LINK = 'portal_link_id';

    /** The context key naming whoever minted that link — the person or the key. */
    public const string CREATED_BY = 'portal_link_created_by';

    public function __construct(
        private string $linkId,
        private string $organizationId,
        private PortalScope $scope,
        private string $createdBy,
    ) {}

    public function kind(): string
    {
        return 'portal';
    }

    public function id(): string
    {
        return $this->linkId;
    }

    public function auditActor(): AuditActor
    {
        return new AuditActor(ActorType::System, $this->linkId);
    }

    /**
     * Allowed only when one of the session's intents lists this action — and the session's
     * intents are already only the ones the plan still includes ({@see AdminPortal::principal()}).
     * Re-asked here regardless, so a principal built any other way is held to the same rule.
     */
    public function authorize(ActionDefinition $action): void
    {
        if ($action->plane !== ActionPlane::Environment) {
            throw new AuthorizationException('The Admin Portal cannot do this.');
        }

        foreach ($this->scope->intentsFor($action->name) as $intent) {
            if (app(AdminPortal::class)->intentUsable($this->organizationId, $intent)) {
                return;
            }
        }

        throw new AuthorizationException('This setup link does not cover that.');
    }

    /** A person at a browser: the redirect after a POST guards a double submit. */
    public function supportsIdempotency(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Admin Portal';
    }

    /** The link is the approval: whoever minted it decided what it may set up. */
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

    public function confinedToOrganization(): string
    {
        return $this->organizationId;
    }

    public function trailContext(): array
    {
        return array_filter([
            self::LINK => $this->linkId,
            self::CREATED_BY => $this->createdBy,
        ], static fn (string $value): bool => $value !== '');
    }

    public function scope(): PortalScope
    {
        return $this->scope;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}

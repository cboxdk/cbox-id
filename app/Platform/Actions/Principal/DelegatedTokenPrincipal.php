<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\AccountScopes;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\CustomerConsole;
use App\Platform\EnvironmentKeyAuditLog;
use App\Platform\OAuth\DelegatedAccess;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A PERSON acting through an agent or a CLI they signed in — an OAuth access token this
 * environment issued, audienced to its management plane ({@see DelegatedAccess} reads and
 * vouches for it).
 *
 * TWO LIMITS, BOTH ASKED. The token must carry the action's scope: that is what the person
 * agreed to hand the client, on the consent screen or the device page. And the person must
 * be allowed to do it here at all — what they could do on their own console in this
 * environment, asked the way {@see ConsoleSessionPrincipal} asks it. A scope never adds a
 * right the person does not hold, and a right the person holds is not handed to a client
 * that was not given its scope.
 *
 * WHICH CONSOLE THAT IS. The token was issued by THIS environment's issuer, so the person is
 * one of its subjects, signed in on its own host — the organization console, the plane
 * {@see ConsoleScope::plane()} answers for anybody with a subject session. Not the
 * environment console: an environment's administrators are people of the platform root,
 * who reach a tenant's host through a signed handoff and are never its subjects, so no
 * token this issuer mints can stand for one. So, exactly as the console has it:
 *
 *  - a {@see ConsoleGate::Administer} action needs the person to administer the
 *    organization the token is bound to (their role there, from an active membership of a
 *    live organization — {@see OrganizationChoice}), and reaches that organization and
 *    nothing else ({@see confinedToOrganization()});
 *  - on a customer's environment of a multi-tenant deployment, only what a customer's own
 *    console offers ({@see CustomerConsole}) — the product's administration stays with the
 *    vendor's environment console there, whichever door asks;
 *  - a {@see ConsoleGate::EnvironmentAdmin} action is the environment console's, and is
 *    refused;
 *  - the workspace plane is above every environment and reached with a workspace key, and
 *    is refused.
 *
 * AND THE PERSON'S OWN ACCOUNT. The account plane (`/api/v1/me`) is served on the host of
 * the environment the person belongs to — this one — and asks nothing of them but that
 * they delegated the `account:*` scope: every account action is keyed to
 * {@see subjectId()}, never to an id the caller names, so there is no other account to
 * reach. That is why this is a {@see PersonPrincipal} as well.
 *
 * CRITICAL ALWAYS WAITS FOR THE PERSON. A key's owner chooses its step-up policy; a token
 * has no owner but the person it stands for, and an agent holding it may be acting on a
 * prompt nobody read. So every {@see Danger::Critical} action is held for the person's own
 * approval on their device ({@see StepUpPolicy()}), in the environment they belong to.
 *
 * The trail names the person — a user of this environment, as their console's acts are —
 * and every entry the action causes records the client they used
 * ({@see EnvironmentKeyAuditLog}).
 */
final readonly class DelegatedTokenPrincipal implements PersonPrincipal, SignedInPerson
{
    /**
     * @param  list<string>  $scopes  the scopes the token carries
     * @param  OrganizationChoice|null  $organization  the organization the token is bound to, while the person is an active member of it
     * @param  bool  $customerConsole  whether this is a customer's environment, whose own console is an admin portal
     */
    public function __construct(
        private string $subjectId,
        private string $personName,
        private string $clientId,
        private string $clientName,
        private string $environmentId,
        private array $scopes,
        private ?OrganizationChoice $organization,
        private bool $customerConsole,
        private ?int $expiresAt = null,
    ) {}

    public function kind(): string
    {
        return 'delegated';
    }

    /**
     * The person AND the client, so two agents one person signed in never share an
     * idempotency key or an approval. The client's id is hashed because a metadata
     * document client's is a URL of up to 255 characters, and this id is stored beside the
     * kind in a 100-character column.
     */
    public function id(): string
    {
        return $this->subjectId.':'.substr(hash('sha256', $this->clientId), 0, 32);
    }

    public function auditActor(): AuditActor
    {
        return AuditActor::user($this->subjectId);
    }

    public function authorize(ActionDefinition $action): void
    {
        if ($action->plane === ActionPlane::Account) {
            if (! AccountScopes::knows($action->scope) || ! $this->grants($action->scope)) {
                throw new AuthorizationException("This sign-in was not granted the required scope: {$action->scope}.");
            }

            return;
        }

        if ($action->plane !== ActionPlane::Environment) {
            throw new AuthorizationException('A signed-in token reaches this environment\'s actions only. The workspace is reached with a workspace key.');
        }

        if (! app(ManagementScopes::class)->knows($action->scope) || ! in_array($action->scope, $this->scopes, true)) {
            throw new AuthorizationException("This sign-in was not granted the required scope: {$action->scope}.");
        }

        if ($action->consoleGate !== ConsoleGate::Administer) {
            throw new AuthorizationException('This belongs to the environment, and is administered from the environment console.');
        }

        if ($this->organization === null || ! $this->organization->role->canManageOrganization()) {
            throw new AuthorizationException('You do not have permission to change this.');
        }

        if ($this->customerConsole && ! self::customerConsoleOffers($action)) {
            throw new AuthorizationException('Your organization\'s console does not offer this here, so a sign-in to it cannot do it either.');
        }
    }

    public function supportsIdempotency(): bool
    {
        return true;
    }

    public function label(): string
    {
        return '"'.$this->clientName.'" for '.$this->personName;
    }

    /** Every critical action, whatever else: the product's floor for a token, not a choice. */
    public function stepUpPolicy(): StepUpPolicy
    {
        return new StepUpPolicy(Danger::Critical);
    }

    public function approverSubjectId(): string
    {
        return $this->subjectId;
    }

    public function approverEnvironmentId(): string
    {
        return $this->environmentId;
    }

    /**
     * The organization the token is bound to. Refused outright when there is none: null
     * here would be the environment's whole authority, which no person's token carries.
     */
    public function confinedToOrganization(): string
    {
        return $this->organization->id ?? throw new AuthorizationException('This sign-in is not bound to an organization.');
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function personName(): string
    {
        return $this->personName;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function clientName(): string
    {
        return $this->clientName;
    }

    public function environmentId(): string
    {
        return $this->environmentId;
    }

    public function organization(): ?OrganizationChoice
    {
        return $this->organization;
    }

    /**
     * The management scopes the token carries — what `whoami` reports: the environment's,
     * and the person's own account's. The protocol scopes (`openid`, `offline_access`) ride
     * on every token and grant nothing here.
     *
     * @return list<string>
     */
    public function managementScopes(): array
    {
        $vocabulary = app(ManagementScopes::class);

        return array_values(array_filter($this->scopes, static fn (string $scope): bool => $vocabulary->knows($scope) || AccountScopes::knows($scope)));
    }

    /** A token is no sign-in session: "everywhere else" is everywhere. */
    public function currentSessionId(): ?string
    {
        return null;
    }

    public function grants(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }

    /**
     * Whether a customer's own console runs this action: one of the organization console
     * routes it is the twin of is a page that console keeps. The environment console's
     * routes (`environment.*`) are the vendor's, and never count.
     */
    private static function customerConsoleOffers(ActionDefinition $action): bool
    {
        foreach ($action->consoleRoutes as $route) {
            if (! str_starts_with($route, 'environment.') && CustomerConsole::servesRoute($route)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\AppManagementScopes;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Scopes\ScopeRegistry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * An app's own client-credentials token on the environment plane: the app's backend, acting
 * for nobody, in the environment that issued the token.
 *
 * It reaches only the actions whose scope is marked `machine` in
 * {@see AppManagementScopes::APP_SCOPES} and that the token carries — every other action
 * stays a key's or a person's. Authenticated by {@see AuthenticateEnvironmentApi}; the scope
 * is asked again here, because the route is one door of several.
 */
final readonly class AppTokenPrincipal implements Principal
{
    public function __construct(private string $clientId, private Introspection $token) {}

    public function kind(): string
    {
        return 'app_token';
    }

    public function id(): string
    {
        return $this->clientId;
    }

    public function auditActor(): AuditActor
    {
        return AuditActor::service($this->clientId);
    }

    public function authorize(ActionDefinition $action): void
    {
        if ($action->plane !== ActionPlane::Environment || ! AppManagementScopes::machine($action->scope) || ! ScopeRegistry::satisfiedBy($action->scope, $this->token->hasScope(...))) {
            throw new AuthorizationException("This token is missing the required scope: {$action->scope}.");
        }
    }

    public function supportsIdempotency(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'App "'.$this->clientId.'"';
    }

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

    /** It acts with the environment's authority, bounded by the machine scopes it carries. */
    public function confinedToOrganization(): ?string
    {
        return null;
    }
}

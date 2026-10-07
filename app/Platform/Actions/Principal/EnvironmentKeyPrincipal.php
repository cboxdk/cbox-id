<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * An environment management key (`cbid_env_…`). It acts with the environment's authority,
 * bounded by its scopes; the trail names it as a service.
 *
 * The scope is checked here as well as by {@see AuthenticateEnvironmentApi} on the
 * route: the route is one door, and an action reached any other way (MCP, a batch) must
 * not depend on a middleware it never passed through.
 */
final readonly class EnvironmentKeyPrincipal implements Principal
{
    public function __construct(private EnvironmentApiKey $key) {}

    public function kind(): string
    {
        return 'environment_key';
    }

    public function id(): string
    {
        return (string) $this->key->id;
    }

    public function auditActor(): AuditActor
    {
        return AuditActor::service((string) $this->key->id);
    }

    public function authorize(ActionDefinition $action): void
    {
        $scope = EnvironmentApiScope::tryFrom($action->scope);

        // An environment key reaches its own environment's actions only — the workspace above
        // it is reached with a workspace key, and no scope name can bridge the two.
        if ($action->plane !== ActionPlane::Environment || $scope === null || ! $this->key->isActive() || ! $this->key->can($scope)) {
            throw new AuthorizationException("This key is missing the required scope: {$action->scope}.");
        }
    }

    public function supportsIdempotency(): bool
    {
        return true;
    }

    public function key(): EnvironmentApiKey
    {
        return $this->key;
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\Approvals\StepUpPolicy;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Platform\Contracts\ManagementScopes;
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
        if (! app(ManagementScopes::class)->knows($action->scope) || ! $this->key->isActive() || ! $this->key->can($action->scope)) {
            throw new AuthorizationException("This key is missing the required scope: {$action->scope}.");
        }
    }

    public function supportsIdempotency(): bool
    {
        return true;
    }

    public function label(): string
    {
        return 'Key "'.$this->key->name.'"';
    }

    public function stepUpPolicy(): ?StepUpPolicy
    {
        return StepUpPolicy::fromArray($this->key->step_up_policy);
    }

    /**
     * The person behind this key: whoever minted it in the console, or — for a key minted by
     * a key — whoever minted the first key in that chain. A chain that ends in no person
     * has nobody to ask.
     */
    public function approverSubjectId(): ?string
    {
        $key = $this->key;

        for ($depth = 0; $depth < 32; $depth++) {
            if ($key->created_by_type === 'organization_member' && is_string($key->created_by_id) && $key->created_by_id !== '') {
                return $key->created_by_id;
            }

            if ($key->parent_key_id === null) {
                return null;
            }

            $parent = EnvironmentApiKey::query()->whereKey($key->parent_key_id)->first();

            if ($parent === null) {
                return null;
            }

            $key = $parent;
        }

        return null;
    }

    public function key(): EnvironmentApiKey
    {
        return $this->key;
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\Approvals\StepUpPolicy;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

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
        // An environment key reaches its own environment's actions only — the workspace above
        // it is reached with a workspace key, and no scope name can bridge the two.
        if ($action->plane !== ActionPlane::Environment || ! app(ManagementScopes::class)->knows($action->scope) || ! $this->key->isActive() || ! $this->key->can($action->scope)) {
            throw new AuthorizationException("This key is missing the required scope: {$action->scope}.");
        }
    }

    public function supportsIdempotency(): bool
    {
        return true;
    }

    /**
     * The key AND the environment it acts in — `Key "Deploy agent" in Production` — because
     * the person approving a held action on their phone may own keys in several
     * environments, and a message naming only the key does not say whose webhooks, users
     * or settings it is about to change.
     */
    public function label(): string
    {
        $environment = Environment::query()->whereKey($this->key->environment_id)->value('name');
        $label = 'Key "'.Str::limit($this->key->name, 80).'"';

        return is_string($environment) && $environment !== '' ? $label.' in '.Str::limit($environment, 80) : $label;
    }

    public function stepUpPolicy(): ?StepUpPolicy
    {
        return StepUpPolicy::fromArray($this->key->step_up_policy);
    }

    /**
     * The person behind this key: whoever minted it in the console, or — for a key minted by
     * a key — whoever minted the first key in that chain, across into the workspace when a
     * workspace key minted it. A chain that ends in no person has nobody to ask.
     */
    public function approverSubjectId(): ?string
    {
        $key = $this->key;

        for ($depth = 0; $depth < 32; $depth++) {
            if ($key->created_by_type === 'organization_member' && is_string($key->created_by_id) && $key->created_by_id !== '') {
                return $key->created_by_id;
            }

            // Minted from the workspace by a workspace key: that key's person answers.
            if ($key->created_by_type === 'workspace_key' && is_string($key->created_by_id)) {
                $minter = OrganizationApiKey::query()->whereKey($key->created_by_id)->first();

                return $minter === null ? null : (new WorkspaceKeyPrincipal($minter))->approverSubjectId();
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

    /** The minting chain ends in a person of the platform root, where keys are minted from. */
    public function approverEnvironmentId(): ?string
    {
        return null;
    }

    /** A management key holds the environment's authority, above every organization in it. */
    public function confinedToOrganization(): ?string
    {
        return null;
    }

    public function key(): EnvironmentApiKey
    {
        return $this->key;
    }
}

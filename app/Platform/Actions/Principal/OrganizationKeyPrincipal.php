<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Http\Middleware\AuthenticateOrganizationApi;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\OrganizationScopes;
use App\Platform\OrganizationCapabilities;
use App\Platform\ServiceAccess\ProductAdmission;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A workspace key (`cbid_ws_…`): the machine equivalent of a member of the workspace's
 * team, acting above every environment the workspace owns. The trail names it as a
 * service.
 *
 * THREE QUESTIONS, ALL OF THEM ASKED HERE. The action must be a workspace action — an
 * environment's API is reached with that environment's key on its host, never this one.
 * The key's ROLE must hold the capability the scope needs and the capability the action's
 * gate names ({@see OrganizationScopes}, {@see OrganizationCapabilities}) — the same answer
 * the console gives a person holding that role. And the key must carry the scope
 * ({@see OrganizationApiKey::permits()}: a key minted with no scopes is bounded by its
 * role alone).
 *
 * Checked here as well as by {@see AuthenticateOrganizationApi} on the route, for the reason
 * {@see EnvironmentKeyPrincipal} gives: an action reached any other way must not depend on
 * a middleware it never passed through.
 */
final readonly class OrganizationKeyPrincipal implements Principal
{
    public function __construct(private OrganizationApiKey $key) {}

    public function kind(): string
    {
        return 'workspace_key';
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
        if ($action->plane !== ActionPlane::Organization || ! $this->key->isActive()) {
            throw new AuthorizationException('An organization key cannot run this.');
        }

        if ($action->name === 'team.transfer_ownership') {
            throw new AuthorizationException('Ownership transfer requires the current owner signed in as a person.');
        }

        $can = OrganizationCapabilities::forMachineRole($this->key->role);

        foreach ([OrganizationScopes::capability($action->scope), $action->consoleGate->capability()] as $capability) {
            if ($capability === false || ! OrganizationScopes::holds($can, $capability)) {
                throw new AuthorizationException("This key's role may not ".($capability === false ? 'run this' : $capability).'.');
            }
        }

        if (! OrganizationScopes::knows($action->scope) || ! $this->key->permits($action->scope)) {
            throw new AuthorizationException("This key is missing the required scope: {$action->scope}.");
        }
        if (in_array($action->scope, ['workspace:read', 'projects:write', 'environments:write', 'keys:write'], true)) {
            app(ProductAdmission::class)->assertGranted($this->id(), $this->key->organization_id, acting: $action->danger->writes());
        }
    }

    public function supportsIdempotency(): bool
    {
        return true;
    }

    public function label(): string
    {
        return 'Organization key "'.$this->key->name.'"';
    }

    public function stepUpPolicy(): ?StepUpPolicy
    {
        return StepUpPolicy::fromArray($this->key->step_up_policy);
    }

    /**
     * The person behind this key: the team member who minted it in the console, or — for a
     * key minted by a key — whoever minted the first key in that chain. A chain that ends
     * in no person has nobody to ask.
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

            $parent = OrganizationApiKey::query()->whereKey($key->parent_key_id)->first();

            if ($parent === null) {
                return null;
            }

            $key = $parent;
        }

        return null;
    }

    /** The minting chain ends in a member of the workspace, a subject of the platform root. */
    public function approverEnvironmentId(): ?string
    {
        return null;
    }

    /** Nothing on an environment's plane: it never runs an environment's actions at all. */
    public function confinedToOrganization(): ?string
    {
        return null;
    }

    public function key(): OrganizationApiKey
    {
        return $this->key;
    }

    /** What the key's role may do — the same object the console asks for a person. */
    public function capabilities(): OrganizationCapabilities
    {
        return OrganizationCapabilities::forMachineRole($this->key->role);
    }
}

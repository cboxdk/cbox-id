<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Http\Middleware\AuthenticateWorkspaceApi;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\WorkspaceScopes;
use App\Platform\OrganizationCapabilities;
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
 * gate names ({@see WorkspaceScopes}, {@see OrganizationCapabilities}) — the same answer
 * the console gives a person holding that role. And the key must carry the scope
 * ({@see OrganizationApiKey::permits()}: a key minted with no scopes is bounded by its
 * role alone).
 *
 * Checked here as well as by {@see AuthenticateWorkspaceApi} on the route, for the reason
 * {@see EnvironmentKeyPrincipal} gives: an action reached any other way must not depend on
 * a middleware it never passed through.
 */
final readonly class WorkspaceKeyPrincipal implements Principal
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
        if ($action->plane !== ActionPlane::Workspace || ! $this->key->isActive()) {
            throw new AuthorizationException('A workspace key cannot run this.');
        }

        $can = OrganizationCapabilities::of($this->key->role);

        foreach ([WorkspaceScopes::capability($action->scope), $action->consoleGate->capability()] as $capability) {
            if ($capability === false || ! WorkspaceScopes::holds($can, $capability)) {
                throw new AuthorizationException("This key's role may not ".($capability === false ? 'run this' : $capability).'.');
            }
        }

        if (! WorkspaceScopes::knows($action->scope) || ! $this->key->permits($action->scope)) {
            throw new AuthorizationException("This key is missing the required scope: {$action->scope}.");
        }
    }

    public function supportsIdempotency(): bool
    {
        return true;
    }

    public function label(): string
    {
        return 'Workspace key "'.$this->key->name.'"';
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

    public function key(): OrganizationApiKey
    {
        return $this->key;
    }

    /** What the key's role may do — the same object the console asks for a person. */
    public function capabilities(): OrganizationCapabilities
    {
        return OrganizationCapabilities::of($this->key->role);
    }
}

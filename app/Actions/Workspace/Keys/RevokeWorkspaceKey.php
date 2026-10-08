<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Keys;

use App\Actions\Workspace\InWorkspace;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Id\Platform\Models\Project;

/**
 * Revoke a workspace key — and every key it minted, all the way down, on BOTH planes: the
 * workspace keys it minted, and the management keys it minted for the workspace's
 * environments (an `initial_key`, a `keys.environment.create`) with everything those keys
 * minted in turn. Whatever uses them stops immediately. A credential an agent was handed by
 * a key outlives that key nowhere.
 *
 * Only a key of THIS workspace: the id comes from the URL, and a revoke by id alone would
 * let one workspace stop another's automation. An already-revoked key in the tree is passed
 * over rather than recorded again; the log is the act that stopped it.
 */
#[AsAction(
    name: 'keys.workspace.revoke',
    summary: 'Revoke a workspace key, and every key it minted on either plane (workspace keys and environment management keys, all the way down); whatever uses them stops immediately.',
    scope: 'keys:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Workspace,
    rest: ['DELETE', '/keys/{id}'],
    status: 204,
    consoleRoutes: ['keys.workspace.destroy'],
    consoleGate: ConsoleGate::ManageMembers,
    tag: 'Keys',
)]
final readonly class RevokeWorkspaceKey implements Action
{
    public function __construct(
        private OrganizationApiKeys $keys,
        private EnvironmentApiKeys $environmentKeys,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('id')->inPath()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $key = OrganizationApiKey::query()
            ->whereKey($context->string('id'))
            ->where('organization_id', $workspaceId)
            ->first() ?? throw ActionRefused::notFound('key');

        $queue = [$key];
        $tree = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            $tree[] = $current->id;

            if ($current->revoked_at === null) {
                $this->keys->revoke($current->id);

                // The console's entry exactly for the key named; a key revoked because its
                // parent was says so.
                InWorkspace::record($context->principal, $workspaceId, 'organization.api_key_revoked', 'api_key', $current->id, $current->id === $key->id
                    ? ['name' => $current->name]
                    : ['name' => $current->name, 'because_parent_revoked' => true]);
            }

            foreach (OrganizationApiKey::query()->where('parent_key_id', $current->id)->where('organization_id', $workspaceId)->get() as $child) {
                $queue[] = $child;
            }
        }

        $this->revokeMintedEnvironmentKeys($context, $workspaceId, $tree);

        return ActionResult::none($key);
    }

    /**
     * The environment management keys any key in the revoked tree minted, and their own
     * descendants — walked even when already revoked, so a child that outlived its parent is
     * stopped now; only what changes is recorded.
     *
     * @param  list<string>  $workspaceKeyIds
     */
    private function revokeMintedEnvironmentKeys(ActionContext $context, string $workspaceId, array $workspaceKeyIds): void
    {
        $environments = Environment::query()
            ->whereIn('project_id', Project::query()->where('organization_id', $workspaceId)->pluck('id'))
            ->get(['id']);

        foreach ($environments as $environment) {
            $environmentId = $environment->id;
            $keys = $this->environmentKeys->forEnvironment($environmentId);
            $queue = $keys
                ->filter(static fn ($key): bool => $key->created_by_type === 'workspace_key' && in_array($key->created_by_id, $workspaceKeyIds, true))
                ->values()
                ->all();

            while ($queue !== []) {
                $current = array_shift($queue);

                if ($current->revoked_at === null) {
                    $this->environmentKeys->revoke($environmentId, $current->id);

                    InWorkspace::record($context->principal, $workspaceId, 'organization.environment_key_revoked', 'environment', $environmentId, [
                        'key_id' => $current->id,
                        'name' => $current->name,
                        'because_parent_revoked' => true,
                    ]);
                }

                foreach ($keys->where('parent_key_id', $current->id) as $child) {
                    $queue[] = $child;
                }
            }
        }
    }
}

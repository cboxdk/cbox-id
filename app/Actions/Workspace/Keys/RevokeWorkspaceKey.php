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
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\OrganizationApiKey;

/**
 * Revoke a workspace key — and every key it minted, all the way down. Whatever uses them
 * stops immediately.
 *
 * Only a key of THIS workspace: the id comes from the URL, and a revoke by id alone would
 * let one workspace stop another's automation. An already-revoked key in the tree is passed
 * over rather than recorded again; the log is the act that stopped it.
 */
#[AsAction(
    name: 'keys.workspace.revoke',
    summary: 'Revoke a workspace key, and every key it minted; whatever uses them stops immediately.',
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
    public function __construct(private OrganizationApiKeys $keys) {}

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

        while ($queue !== []) {
            $current = array_shift($queue);

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

        return ActionResult::none($key);
    }
}

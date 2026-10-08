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
use App\Platform\Keys\ManagementKeys;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;

/**
 * Revoke one of an environment's management keys; whatever uses it stops, immediately.
 *
 * Only a key of the named — and reachable — environment: the key id comes from the URL,
 * and a revoke by id alone would let one workspace stop another's automation. A key that is
 * already revoked stays revoked and records nothing new: the log is the act that stopped
 * it, not every request naming a row that no longer offers one.
 *
 * AND EVERY KEY IT MINTED, all the way down, as the environment plane's own revoke does
 * ({@see ManagementKeys::revoke()}). This one stopped at the key named: an agent's key
 * revoked from the workspace console left the narrower keys it had minted working, so the
 * one control a person reaches for when an agent goes wrong did not stop the agent. Each
 * key revoked because its parent was says so on the log.
 */
#[AsAction(
    name: 'keys.environment.revoke',
    summary: 'Revoke one of an environment\'s management keys; whatever uses it stops immediately.',
    scope: 'keys:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Workspace,
    rest: ['DELETE', '/environments/{environment_id}/keys/{id}'],
    status: 204,
    consoleRoutes: ['keys.destroy'],
    consoleGate: ConsoleGate::ManageEnvironments,
    tag: 'Keys',
)]
final readonly class RevokeEnvironmentKey implements Action
{
    public function __construct(private EnvironmentApiKeys $keys) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('environment_id')->inPath(),
            Field::string('id')->inPath(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $environment = InWorkspace::environment($context->principal, $workspaceId, $context->string('environment_id'));

        $keys = $this->keys->forEnvironment($environment->id);
        $found = $keys->firstWhere('id', $context->string('id')) ?? throw ActionRefused::notFound('key');

        // Walked even when the key named is revoked already, so a child that outlived its
        // parent is stopped the next time anyone revokes the parent; only what changes now
        // is recorded.
        $queue = [$found];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current->revoked_at === null) {
                $this->keys->revoke($environment->id, $current->id);

                InWorkspace::record($context->principal, $workspaceId, 'organization.environment_key_revoked', 'environment', $environment->id, $current->id === $found->id
                    ? ['key_id' => $current->id, 'name' => $current->name]
                    : ['key_id' => $current->id, 'name' => $current->name, 'because_parent_revoked' => true]);
            }

            foreach ($keys->where('parent_key_id', $current->id) as $child) {
                $queue[] = $child;
            }
        }

        return ActionResult::none($found);
    }
}

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
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;

/**
 * Revoke one of an environment's management keys; whatever uses it stops, immediately.
 *
 * Only a key of the named — and reachable — environment: the key id comes from the URL,
 * and a revoke by id alone would let one workspace stop another's automation. A key that is
 * already revoked stays revoked and records nothing new: the log is the act that stopped
 * it, not every request naming a row that no longer offers one.
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

        $found = $this->keys->forEnvironment($environment->id)->firstWhere('id', $context->string('id'))
            ?? throw ActionRefused::notFound('key');

        if ($found->revoked_at !== null) {
            return ActionResult::none($found);
        }

        $this->keys->revoke($environment->id, $found->id);

        InWorkspace::record($context->principal, $workspaceId, 'organization.environment_key_revoked', 'environment', $environment->id, [
            'key_id' => $found->id,
            'name' => $found->name,
        ]);

        return ActionResult::none($found);
    }
}

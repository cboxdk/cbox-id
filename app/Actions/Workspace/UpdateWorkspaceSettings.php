<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

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
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Change the workspace's settings — today, its name.
 *
 * Renamed on the model rather than through a contract verb: `Organizations` has no
 * rename(). IN THE PLATFORM ROOT, because the WRITE is guarded too: `BelongsToEnvironment`
 * refuses a cross-environment save outright, so a rename issued from any other host would
 * raise rather than silently write nowhere.
 *
 * Recorded on the workspace's own log as `organization.renamed`, the action the
 * environment console's Settings page writes — one act, one name, whichever page or key
 * did it. Unchanged is not an act, and records nothing.
 */
#[AsAction(
    name: 'workspace.settings.update',
    summary: 'Rename the workspace.',
    scope: 'settings:write',
    danger: Danger::Write,
    plane: ActionPlane::Workspace,
    rest: ['PATCH', '/'],
    consoleRoutes: ['organization-settings.update'],
    consoleGate: ConsoleGate::ManageMembers,
    schema: 'Organization',
    tag: 'Workspace',
)]
final readonly class UpdateWorkspaceSettings implements Action
{
    public function __construct(
        private Organizations $organizations,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(120),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);

        $organization = $this->platformRoot->run(fn (): ?Organization => $this->organizations->find($workspaceId))
            ?? throw ActionRefused::notFound('organization');

        $from = $organization->name;
        $to = trim($context->string('name'));

        if ($to === '') {
            throw ActionRefused::because('validation_failed', 'The workspace needs a name.', 'name');
        }

        if ($to !== $from) {
            $this->platformRoot->run(fn () => $organization->forceFill(['name' => $to])->save());

            InWorkspace::record($context->principal, $organization->id, 'organization.renamed', 'organization', $organization->id, [
                'from' => $from,
                'to' => $to,
            ]);
        }

        return ActionResult::item($organization, [
            'id' => $organization->id,
            'name' => $organization->name,
            'status' => $organization->status->value,
        ]);
    }
}

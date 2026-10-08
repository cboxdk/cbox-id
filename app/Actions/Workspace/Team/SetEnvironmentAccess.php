<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Team;

use App\Actions\Workspace\InWorkspace;
use App\Http\Resources\Workspace\MemberResource;
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
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Which of the workspace's environments a member reaches: every one, or a chosen few.
 *
 * The ids must be the WORKSPACE's environments. A grant to an environment of another
 * workspace would mean nothing today and something tomorrow, so it is refused rather than
 * stored.
 */
#[AsAction(
    name: 'team.environment_access',
    summary: 'Set which of the workspace\'s environments a team member reaches: all of them, or the listed ones.',
    scope: 'team:write',
    danger: Danger::Write,
    plane: ActionPlane::Workspace,
    rest: ['PUT', '/members/{id}/access'],
    consoleRoutes: ['members.access'],
    consoleGate: ConsoleGate::ManageMembers,
    schema: 'Member',
    tag: 'Team',
)]
final readonly class SetEnvironmentAccess implements Action
{
    public function __construct(
        private Memberships $members,
        private Subjects $subjects,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath(),
            Field::boolean('all_environments')->required()->describe('True: every environment, including ones created later.'),
            Field::list('environment_ids', Field::string('environment_id')->max(64))->describe('With `all_environments` false: the environments they reach.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $target = ManageableMember::resolve($context->principal, $workspaceId, $context->string('id'));
        $all = $context->boolean('all_environments');
        $ids = array_values(array_unique(array_filter($context->array('environment_ids'), 'is_string')));

        $owned = Environment::query()
            ->whereIn('project_id', Project::query()->where('organization_id', $workspaceId)->pluck('id'))
            ->whereIn('id', $ids)
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->all();

        $foreign = array_values(array_diff($ids, $owned));

        if (! $all && $foreign !== []) {
            throw ActionRefused::because('environment_not_found', 'Not an environment of this workspace: '.implode(', ', $foreign).'.', 'environment_ids');
        }

        $this->platformRoot->run(fn () => $this->members->setEnvironmentAccess($workspaceId, $target->user_id, $all, $ids));

        $updated = $this->platformRoot->run(
            fn (): ?Membership => $this->members->forOrganization($workspaceId)->firstWhere('id', $target->id),
        ) ?? $target;
        $person = $this->platformRoot->run(fn () => $this->subjects->find($target->user_id));

        return ActionResult::item($updated, MemberResource::from($updated, $person));
    }
}

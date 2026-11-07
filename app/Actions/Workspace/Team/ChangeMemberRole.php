<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Team;

use App\Actions\Workspace\InWorkspace;
use App\Http\Resources\Workspace\MemberResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\NativeProduct\NativeProductManifest;
use App\Platform\NativeProduct\NativeProductMembers;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Change a member's role. Assignable roles only — Owner is transferred, never granted —
 * and never the owner's own role or the actor's ({@see ManageableMember}). The workspace id
 * comes from the principal and the person from the fenced lookup, so neither is the string
 * off the wire.
 */
#[AsAction(
    name: 'team.role',
    summary: 'Replace native ID app roles atomically. Shared organization membership is preserved.',
    scope: 'team:write',
    danger: Danger::Write,
    plane: ActionPlane::Workspace,
    rest: ['PATCH', '/members/{id}/role'],
    consoleRoutes: ['members.role'],
    consoleGate: ConsoleGate::ManageMembers,
    schema: 'Member',
    tag: 'Team',
)]
final readonly class ChangeMemberRole implements Action
{
    public function __construct(
        private NativeProductMembers $members,
        private Subjects $subjects,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath(),
            Field::list('roles', Field::string('role')->oneOf(array_keys(NativeProductManifest::ROLES)))->required(),
            Field::string('roles_version')->required(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $target = ManageableMember::resolve($context->principal, $workspaceId, $context->string('id'));
        $this->members->replace($target, array_values(array_filter($context->array('roles'), 'is_string')), $context->string('roles_version'), $context->actor());
        $updated = $target;

        $person = $this->platformRoot->run(fn () => $this->subjects->find($target->user_id));

        return ActionResult::item($updated, MemberResource::from($updated, $person));
    }
}

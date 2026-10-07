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
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Change a member's role. Assignable roles only — Owner is transferred, never granted —
 * and never the owner's own role or the actor's ({@see ManageableMember}). The workspace id
 * comes from the principal and the person from the fenced lookup, so neither is the string
 * off the wire.
 */
#[AsAction(
    name: 'team.role',
    summary: 'Change a team member\'s role (admin, developer, member or viewer). The owner\'s role changes only by transferring ownership.',
    scope: 'team:write',
    danger: Danger::Write,
    plane: ActionPlane::Workspace,
    rest: ['PATCH', '/members/{id}/role'],
    consoleRoutes: ['members.role'],
    consoleGate: ConsoleGate::ManageMembers,
    schema: 'Member',
    tag: 'Members',
)]
final readonly class ChangeMemberRole implements Action
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
            Field::string('role')->required()->oneOf(array_map(static fn (MembershipRole $role): string => $role->value, MembershipRole::assignable())),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $target = ManageableMember::resolve($context->principal, $workspaceId, $context->string('id'));
        $role = MembershipRole::from($context->string('role'));

        $updated = $this->platformRoot->run(fn () => $this->members->changeRole($workspaceId, $target->user_id, $role)) ?? $target;

        InWorkspace::record($context->principal, $workspaceId, 'organization.member_role_changed', 'membership', $target->id, [
            'role' => $role->value,
        ]);

        $person = $this->platformRoot->run(fn () => $this->subjects->find($target->user_id));

        return ActionResult::item($updated, MemberResource::from($updated, $person));
    }
}

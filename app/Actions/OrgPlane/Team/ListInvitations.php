<?php

declare(strict_types=1);

namespace App\Actions\OrgPlane\Team;

use App\Actions\OrgPlane\InOrganization;
use App\Http\Resources\OrgPlane\MemberResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\NativeProduct\NativeTeamInvitations as TeamInvitations;

/**
 * The team's invitations nobody has accepted yet, newest first — at most 100, which is
 * more than any workspace has outstanding at once.
 */
#[AsAction(
    name: 'team.invitations.list',
    summary: 'List the team\'s pending invitations, newest first.',
    scope: 'id:members:read',
    danger: Danger::Read,
    plane: ActionPlane::Organization,
    rest: ['GET', '/invitations'],
    consoleGate: ConsoleGate::ReadMembers,
    schema: 'TeamInvitation',
    tag: 'Team',
)]
final readonly class ListInvitations implements Action
{
    public const int LIMIT = 100;

    public function __construct(private TeamInvitations $team) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $pending = $this->team->pending(InOrganization::id($context->principal), self::LIMIT);

        return ActionResult::items($pending, array_map(MemberResource::invitation(...), $pending));
    }
}

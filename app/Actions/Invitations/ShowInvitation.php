<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Actions\Organizations\OrganizationFields;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;

#[AsAction(
    name: 'invitations.get',
    summary: 'Get an invitation and its acceptance receipt, confined to its organization.',
    scope: 'invitations:read',
    danger: Danger::Read,
    schema: 'Invitation',
    tag: 'Invitations',
    rest: ['GET', '/organizations/{organization_id}/invitations/{invitation_id}'],
)]
final readonly class ShowInvitation implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('invitation_id')->inPath()->max(64)->describe('The invitation id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $invitation = InvitationFields::find($organization->id, $context->string('invitation_id'));

        return ActionResult::item($invitation, InvitationFields::present($invitation));
    }
}

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
use App\Platform\Actions\Paginates;
use Cbox\Id\Organization\Enums\InvitationStatus;
use Cbox\Id\Organization\Models\Invitation;
use Illuminate\Database\Eloquent\Collection;

/**
 * An organization's PENDING invitations — the ones whose link still works — a page at a
 * time, each with the access roles parked for it and the app it leads back to.
 */
#[AsAction(
    name: 'invitations.list',
    summary: 'List an organization\'s pending invitations, a page at a time, with the roles each will grant and the app it leads back to.',
    scope: 'invitations:read',
    danger: Danger::Read,
    schema: 'Invitation',
    tag: 'Invitations',
    rest: ['GET', '/organizations/{organization_id}/invitations'],
)]
final class ListInvitations implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));

        $result = $this->page(
            Invitation::query()
                ->where('organization_id', $organization->id)
                ->where('status', InvitationStatus::Pending->value)
                ->where('expires_at', '>', now()),
            $context,
            static fn (Invitation $invitation): array => ['id' => $invitation->id],
        );

        /** @var Collection<int, Invitation> $rows */
        $rows = $result->value;

        return ActionResult::page($rows, InvitationFields::presentMany($organization->id, array_values($rows->all())), $result->meta ?? []);
    }
}

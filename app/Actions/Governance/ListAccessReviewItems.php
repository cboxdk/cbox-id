<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Governance\Models\CertificationItem;

/**
 * A review's worklist: one item per role assignment or membership it snapshotted, with
 * the decision recorded on each and — once closed — whether a revoke could be applied.
 *
 * Paged, because the snapshot grows with the organization's people. People are named by
 * subject id; `GET /users/{id}` says who they are.
 */
#[AsAction(
    name: 'access_reviews.items.list',
    summary: 'List the items an access review asks someone to certify or revoke: who holds what, and the decision on each.',
    scope: 'governance:read',
    danger: Danger::Read,
    schema: 'AccessReviewItem',
    tag: 'Governance',
    rest: ['GET', '/access-reviews/{id}/items'],
)]
final class ListAccessReviewItems implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The review\'s id.'),
            EnterpriseReach::narrowField(),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $campaign = AccessReviewFields::campaign($context);

        return $this->page(
            CertificationItem::query()->where('campaign_id', $campaign->id),
            $context,
            AccessReviewFields::presentItem(...),
        );
    }
}

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
use App\Platform\Enterprise\EnterpriseReach;

/**
 * One access review: whether it is open, when it is due, and how many items it holds.
 * The items themselves are {@see ListAccessReviewItems}.
 */
#[AsAction(
    name: 'access_reviews.get',
    summary: 'Read one access review: its status, due date, pending-item policy and item count.',
    scope: 'governance:read',
    danger: Danger::Read,
    schema: 'AccessReview',
    tag: 'Governance',
    rest: ['GET', '/access-reviews/{id}'],
)]
final class ShowAccessReview implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The review\'s id.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $campaign = AccessReviewFields::campaign($context);

        return ActionResult::item($campaign, AccessReviewFields::present($campaign));
    }
}

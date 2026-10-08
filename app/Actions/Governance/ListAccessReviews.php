<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Enterprise\EnterpriseReach;

/**
 * The access reviews in this environment: with an organization named, its reviews and
 * the staff reviews that cover it; without, every one.
 */
#[AsAction(
    name: 'access_reviews.list',
    summary: 'List access reviews (certification campaigns), open and closed, optionally those covering one organization.',
    scope: 'governance:read',
    danger: Danger::Read,
    schema: 'AccessReview',
    tag: 'Governance',
    rest: ['GET', '/access-reviews'],
)]
final class ListAccessReviews implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            EnterpriseReach::narrowField(list: true)->describe('Only reviews covering this organization: its own and the staff reviews.'),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(AccessReviewFields::fenced($context), $context, AccessReviewFields::present(...));
    }
}

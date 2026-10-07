<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Enums\CampaignStatus;

/**
 * Close an access review and APPLY it: every revoke recorded on it is carried out, and
 * every item still undecided gets the review's own policy — revoke, by default. A staff
 * review's revokes take a staff grant back in every organization at once.
 *
 * Destructive: access is taken away, in bulk, and getting it back means granting it again.
 * Only an OPEN review closes — closing a closed one would re-apply decisions that already
 * took effect, against a roster that has moved on. The framework records
 * `governance.campaign_closed` and every revoke it applies.
 */
#[AsAction(
    name: 'access_reviews.close',
    summary: 'Close an access review and apply it: every revoke is carried out, and undecided items get the review\'s pending policy (revoke by default).',
    scope: 'governance:write',
    danger: Danger::Destructive,
    schema: 'AccessReview',
    tag: 'Governance',
    rest: ['POST', '/access-reviews/{id}/close'],
    consoleRoutes: ['governance.close', 'environment.governance.close'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class CloseAccessReview implements Action
{
    public function __construct(private AccessReviews $reviews) {}

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

        if ($campaign->status !== CampaignStatus::Open) {
            throw new ActionRefused('review_closed', 'This access review is already closed.', 409);
        }

        $closed = $this->reviews->close($campaign->id, AccessReviewFields::writeOrganizationId($context, $campaign));

        return ActionResult::item($closed, AccessReviewFields::present($closed));
    }
}

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
use Cbox\Id\Governance\Models\CertificationItem;

/**
 * Record a decision on one item of an open review: certify (keep it) or revoke (take it
 * away when the review closes). A reviewer can change their mind until then — the two
 * are one act with two answers, so it is one action with an explicit `decision`.
 *
 * The item is resolved inside a review the caller may reach, and the framework fences it
 * on the organization too; the reviewer on record is whoever decided — the person, or the
 * key.
 */
#[AsAction(
    name: 'access_reviews.items.decide',
    summary: 'Certify or revoke one item of an open access review. Revokes are applied when the review closes.',
    scope: 'governance:write',
    danger: Danger::Write,
    schema: 'AccessReviewItem',
    tag: 'Governance',
    rest: ['POST', '/access-reviews/{id}/items/{item_id}'],
    consoleRoutes: ['governance.item', 'environment.governance.item'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DecideAccessReviewItem implements Action
{
    public function __construct(private AccessReviews $reviews) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The review\'s id.'),
            Field::string('item_id')->inPath()->describe('The item\'s id.'),
            Field::string('decision')->required()->oneOf(['certified', 'revoked'])->describe('certified keeps the access; revoked takes it away when the review closes.'),
            Field::string('note')->nullable()->max(1000)->describe('Why, for whoever audits the review.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $campaign = AccessReviewFields::campaign($context);

        if ($campaign->status !== CampaignStatus::Open) {
            throw new ActionRefused('review_closed', 'This access review is closed; its decisions have been applied.', 409);
        }

        // Within THIS review: an item id from another review is a 404, whatever the
        // framework would have said about it.
        CertificationItem::query()->whereKey($context->string('item_id'))->where('campaign_id', $campaign->id)->first()
            ?? throw ActionRefused::notFound('item');

        $organizationId = AccessReviewFields::writeOrganizationId($context, $campaign);
        $reviewer = $context->principal->id();
        $note = $context->nullableString('note');

        $item = $context->string('decision') === 'certified'
            ? $this->reviews->certify($context->string('item_id'), $reviewer, $organizationId, $note)
            : $this->reviews->revoke($context->string('item_id'), $reviewer, $organizationId, $note);

        return ActionResult::item($item, AccessReviewFields::presentItem($item));
    }
}

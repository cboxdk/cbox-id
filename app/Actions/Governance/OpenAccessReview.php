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
use App\Platform\Integrations\IntegrationReach;
use Cbox\Id\Governance\Contracts\AccessReviews;

/**
 * Open an access review: snapshot every direct role assignment and membership in one
 * organization — or, `covers: staff`, every environment-wide (staff) grant — as an item to
 * certify or revoke before it is due.
 *
 * Nothing changes until it is closed ({@see CloseAccessReview}); then every revoke is
 * applied, and anything still undecided is revoked (deny by default). A staff review is
 * the environment's own: only its authority may open one. The framework records
 * `governance.campaign_opened` with whoever opened it.
 */
#[AsAction(
    name: 'access_reviews.create',
    summary: 'Open an access review of one organization\'s roles and memberships — or of staff roles — for someone to certify or revoke.',
    scope: 'governance:write',
    danger: Danger::Write,
    schema: 'AccessReview',
    tag: 'Governance',
    rest: ['POST', '/access-reviews'],
    status: 201,
    consoleRoutes: ['governance.store', 'environment.governance.store'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class OpenAccessReview implements Action
{
    public function __construct(private AccessReviews $reviews) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(190)->describe('What the review is: "Q3 finance access".'),
            Field::string('covers')->oneOf(['organization', 'staff'])->describe('organization (default): one organization\'s access, named by organization_id. staff: every environment-wide staff grant.'),
            Field::string('organization_id')->nullable()->max(64)->describe('The organization to review. Required unless covers is staff.'),
            Field::integer('due_in_days')->min(1)->max(365)->describe('Days until it is due. Default 7.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        if ($context->string('covers') === 'staff') {
            if (IntegrationReach::confinedTo($context->principal) !== null) {
                throw new ActionRefused('forbidden', 'Only an environment administrator may review staff roles.', 403);
            }

            if ($context->nullableString('organization_id') !== null) {
                throw ActionRefused::because('ambiguous_owner', 'A staff review covers no organization. Leave organization_id out.', 'organization_id');
            }

            $organizationId = null;
        } else {
            if ($context->nullableString('organization_id') === null) {
                throw ActionRefused::because('organization_required', 'Name an organization — a review snapshots one organization\'s access.', 'organization_id');
            }

            $organizationId = EnterpriseReach::requiredOrganization($context);
        }

        $days = $context->input['due_in_days'] ?? 7;

        $campaign = $this->reviews->open(
            $organizationId,
            trim($context->string('name')),
            now()->addDays(is_numeric($days) ? (int) $days : 7),
            createdBy: $context->principal->id(),
        );

        $campaign->refresh();

        return ActionResult::item($campaign, AccessReviewFields::present($campaign));
    }
}

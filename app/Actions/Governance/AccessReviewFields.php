<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Enums\CampaignStatus;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Governance\Models\CertificationItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Access reviews (certification campaigns) as the management API returns them, and the
 * fence every one of them is reached through. A helper, not an action.
 *
 * EVERY READ AND WRITE IS FENCED TO AN ORGANIZATION, not merely to the environment. Many
 * organizations share one environment, and a review resolved on its id alone handed one
 * organization's administrator another's whole worklist — names, emails, roles — and its
 * close applied every revoke on it inside somebody else's tenant.
 *
 * STAFF REVIEWS (no organization) cover the environment-wide grants every organization
 * shares; only the environment's own authority — its console, or a management key — sees
 * or decides one. An organization administrator's queries are bound to their organization
 * in the WHERE clause, which a null organization never equals.
 */
final class AccessReviewFields
{
    /**
     * The reviews this principal may see: an organization administrator's own; for the
     * environment's authority, the organization it narrowed to plus the staff reviews, or
     * everything.
     *
     * @return Builder<CertificationCampaign>
     */
    public static function fenced(ActionContext $context): Builder
    {
        $confinedTo = EnterpriseReach::confinedTo($context);
        $narrowedTo = EnterpriseReach::narrowedTo($context);
        $query = CertificationCampaign::query();

        if ($confinedTo !== null) {
            return $query->where('organization_id', $confinedTo);
        }

        return $query->when($narrowedTo !== null, fn (Builder $scoped): Builder => $scoped->where(
            fn (Builder $either): Builder => $either->where('organization_id', $narrowedTo)->orWhereNull('organization_id'),
        ));
    }

    /** @throws ActionRefused */
    public static function campaign(ActionContext $context): CertificationCampaign
    {
        return self::fenced($context)->whereKey($context->string('id'))->first()
            ?? throw ActionRefused::notFound('access review');
    }

    /**
     * The organization a decision or a close is checked against, from the CALLER and never
     * from the review: reading it off the record being written is what once made the
     * framework's ownership assertion compare the review to itself and pass for everyone.
     *
     * A staff review is written as the environment's (null) — reachable only by the
     * environment's authority in the first place. Otherwise the organization the caller
     * narrowed to (an organization administrator always narrows to their own); a key with
     * the environment's authority that named none acts on the review's own organization,
     * which its authority covers.
     */
    public static function writeOrganizationId(ActionContext $context, CertificationCampaign $campaign): ?string
    {
        if ($campaign->organization_id === null) {
            return null;
        }

        return EnterpriseReach::narrowedTo($context) ?? $campaign->organization_id;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(CertificationCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'organization_id' => $campaign->organization_id,
            'staff' => $campaign->organization_id === null,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'open' => $campaign->status === CampaignStatus::Open,
            'pending_policy' => $campaign->pending_policy->value,
            'item_count' => app(AccessReviews::class)->countItemsFor($campaign->id),
            'due_at' => Timestamp::of($campaign->due_at),
            'closed_at' => Timestamp::of($campaign->closed_at),
            'created_by' => $campaign->created_by,
            'created_at' => Timestamp::of($campaign->getAttribute('created_at')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentItem(CertificationItem $item): array
    {
        return [
            'id' => $item->id,
            'review_id' => $item->campaign_id,
            'subject_id' => $item->subject_id,
            'access_type' => $item->access_type->value,
            'access_ref' => $item->access_ref,
            'organization_id' => $item->organization_id,
            'decision' => $item->decision->value,
            'decided_by' => $item->decided_by,
            'decided_at' => Timestamp::of($item->decided_at),
            'note' => $item->note,
            'applied' => (bool) $item->applied,
            'application_note' => $item->application_note,
        ];
    }
}

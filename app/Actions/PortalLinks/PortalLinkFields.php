<?php

declare(strict_types=1);

namespace App\Actions\PortalLinks;

use App\Http\Resources\Environment\Timestamp;
use App\Models\AdminPortalLink;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\AdminPortal;
use App\Platform\Enterprise\EnterpriseReach;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Admin Portal links as the management API lists them — shared by the portal-link actions,
 * and not one itself.
 *
 * THE TOKEN IS NEVER HERE. A link's URL is shown once, in the answer that minted it, and
 * only its hash is stored; nothing here could return it if it tried. What a list says is
 * what an administrator needs to decide whether to withdraw one: what it opens, who minted
 * it and for whom, and where it stands.
 */
final class PortalLinkFields
{
    /**
     * The link $context names, in the organization it names — or a 404. The organization
     * is checked first ({@see EnterpriseReach::requiredOrganization()}: an organization
     * administrator reaches only their own), and the link is looked up INSIDE it, so another
     * organization's link id is the same 404 as a made-up one.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public static function find(ActionContext $context): AdminPortalLink
    {
        $organizationId = EnterpriseReach::requiredOrganization($context, inPath: true);

        return AdminPortalLink::query()
            ->whereKey($context->string('id'))
            ->where('organization_id', $organizationId)
            ->first() ?? throw ActionRefused::notFound('portal link');
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(AdminPortalLink $link): array
    {
        return [
            'id' => $link->id,
            'organization_id' => $link->organization_id,
            'intents' => $link->portalScope()?->values() ?? [],
            'status' => $link->status(app(AdminPortal::class)->sessionMinutes()),
            'created_at' => Timestamp::of($link->created_at),
            'created_by' => $link->created_by,
            'emailed_to' => $link->emailed_to,
            'expires_at' => Timestamp::of($link->expires_at),
            'consumed_at' => Timestamp::of($link->consumed_at),
            'completed_at' => Timestamp::of($link->completed_at),
            'revoked_at' => Timestamp::of($link->revoked_at),
        ];
    }
}

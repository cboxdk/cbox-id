<?php

declare(strict_types=1);

namespace App\Actions\PortalLinks;

use App\Models\AdminPortalLink;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AdminPortal;
use App\Platform\Enterprise\EnterpriseReach;

/**
 * An organization's Admin Portal links — every one still outstanding, and the recent past:
 * the links minted in the last {@see self::DAYS} days, newest first. A link waits a week at
 * most ({@see AdminPortal::MAX_TTL_MINUTES}), so nothing that can still be opened is older.
 *
 * Never the URL. It was shown once, when the link was minted, and only its hash is kept;
 * the list is for deciding what to withdraw ({@see RevokePortalLink}), not for handing a
 * link out again — that is a new link.
 */
#[AsAction(
    name: 'organizations.portal_links.list',
    summary: 'List an organization\'s Admin Portal links from the last 30 days, newest first: what each opens, who minted it, whom it was mailed to, and whether it is pending, in use, completed, expired or revoked. Never the URL.',
    scope: 'portal_links:read',
    danger: Danger::Read,
    schema: 'PortalLinkRecord',
    tag: 'Admin Portal',
    rest: ['GET', '/organizations/{organization_id}/portal-links'],
)]
final readonly class ListPortalLinks implements Action
{
    /** How far back the list reaches. */
    public const int DAYS = 30;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::requiredOrganization($context, inPath: true);

        $links = AdminPortalLink::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return ActionResult::items($links, array_values(array_map(
            static fn (AdminPortalLink $link): array => PortalLinkFields::present($link),
            $links->all(),
        )));
    }
}

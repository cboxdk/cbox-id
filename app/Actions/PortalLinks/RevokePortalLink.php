<?php

declare(strict_types=1);

namespace App\Actions\PortalLinks;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AdminPortal;

/**
 * Withdraw an Admin Portal link. Not yet opened, it can no longer be; opened, the setup
 * session it started ends on its next request — the IT administrator lands on the "this link
 * has expired" page, mid-step if need be. What they already set up stays: withdrawing the
 * link takes back the access, not the connection or directory made with it.
 *
 * Destructive rather than Critical: it hands nobody anything, and the remedy for a link
 * withdrawn by mistake is a new one. Allowed whatever the organization's plan now includes —
 * taking access back is never gated on being entitled to grant it. Withdrawing a link
 * already withdrawn changes nothing and records nothing; one already finished or expired is
 * marked, which is harmless and keeps the answer the same for every state.
 *
 * Recorded as `portal_link.revoked` — the key over the API, the person in the console.
 */
#[AsAction(
    name: 'organizations.portal_links.revoke',
    summary: 'Withdraw an Admin Portal link: it can no longer be opened, and a setup session it already opened ends on its next request. What was already set up through it stays.',
    scope: 'portal_links:write',
    danger: Danger::Destructive,
    tag: 'Admin Portal',
    rest: ['DELETE', '/organizations/{organization_id}/portal-links/{id}'],
    status: 204,
    consoleRoutes: ['environment.organizations.portal-links.revoke'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RevokePortalLink implements Action
{
    public function __construct(private AdminPortal $portal) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('id')->inPath()->max(64)->describe('The portal link\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $link = PortalLinkFields::find($context);

        $this->portal->revoke($link, $context->actor());

        return ActionResult::none($link);
    }
}

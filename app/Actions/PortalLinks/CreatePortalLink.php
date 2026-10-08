<?php

declare(strict_types=1);

namespace App\Actions\PortalLinks;

use App\Http\Resources\Environment\Timestamp;
use App\Models\AdminPortalLink;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AdminPortal;
use App\Platform\Enterprise\EnterpriseReach;
use App\Platform\Enums\PortalScope;

/**
 * Mint a one-time ADMIN PORTAL link: the way an organization's own IT administrator sets
 * up its single sign-on (connection and email domains) or its directory sync without an
 * account here — the link is the whole credential.
 *
 * Critical, and the URL is in `redact`: whoever holds it can, once and for the next
 * half-hour, configure how a whole organization signs in. It is shown in this answer only
 * — only its hash is stored — and an idempotent replay returns everything but the URL. The
 * link is single-use, and redeeming it re-checks the organization's plan, so a lapsed
 * plan cannot be set up through a link minted before it lapsed.
 *
 * {@see AdminPortal::generate()} records `portal_link.created` with whoever minted it: the
 * person in the console, the key over the API.
 */
#[AsAction(
    name: 'organizations.portal_links.create',
    summary: 'Create a one-time Admin Portal link an organization\'s IT administrator uses to set up its SSO and domains, or its directory sync, without an account. The URL is shown once.',
    scope: 'portal_links:write',
    danger: Danger::Critical,
    schema: 'PortalLink',
    tag: 'Admin Portal',
    rest: ['POST', '/organizations/{organization_id}/portal-links'],
    status: 201,
    consoleRoutes: ['connections.invite', 'environment.connections.invite', 'directories.invite', 'environment.directories.invite', 'environment.organizations.portal-links.store'],
    consoleGate: ConsoleGate::Administer,
    redact: ['url'],
)]
final readonly class CreatePortalLink implements Action
{
    public function __construct(private AdminPortal $portal) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization the link sets up.'),
            Field::string('covers')->required()->oneOf(array_map(static fn (PortalScope $scope): string => $scope->value, PortalScope::cases()))
                ->describe('What the link may configure: sso (connection and email domains), scim (directory sync), or both.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::requiredOrganization($context, inPath: true);
        $covers = PortalScope::from($context->string('covers'));

        // Every feature the link covers, not merely one of them: a link that opens a setup
        // screen the plan does not include is a dead end handed to somebody outside.
        foreach ($covers->features() as $feature) {
            EnterpriseReach::assertEntitled($organizationId, $feature->entitlement());
        }

        $token = $this->portal->generate($organizationId, $covers, $context->principal->id());

        $link = AdminPortalLink::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        $url = route('portal.enter', $token);

        return ActionResult::item($url, [
            'id' => $link->id,
            'organization_id' => $organizationId,
            'covers' => $covers->value,
            'url' => $url,
            'expires_at' => Timestamp::of($link->expires_at),
        ]);
    }
}

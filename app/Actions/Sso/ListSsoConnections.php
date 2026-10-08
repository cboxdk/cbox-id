<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Federation\Models\Connection;
use Illuminate\Database\Eloquent\Builder;

/**
 * The SSO connections in this environment — or one organization's — without a secret in
 * sight. With no organization named, every connection the environment holds, its own
 * included: the overview the environment console shows before an organization is chosen.
 */
#[AsAction(
    name: 'sso.connections.list',
    summary: 'List the SAML and OIDC connections organizations sign in through, optionally for one organization. Never a certificate or secret.',
    scope: 'sso:read',
    danger: Danger::Read,
    schema: 'SsoConnection',
    tag: 'Enterprise SSO',
    rest: ['GET', '/sso/connections'],
)]
final class ListSsoConnections implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            EnterpriseReach::narrowField(list: true),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::narrowedTo($context);

        return $this->page(
            Connection::query()->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId)),
            $context,
            SsoFields::present(...),
        );
    }
}

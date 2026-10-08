<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;

/**
 * One SSO connection: what an administrator copies into the identity provider, and what
 * it says back. The certificate, client secret and signing key stay sealed.
 */
#[AsAction(
    name: 'sso.connections.get',
    summary: 'Read one SSO connection: its type, status, entity ids, URLs, issuer and client id. Never a certificate or secret.',
    scope: 'sso:read',
    danger: Danger::Read,
    schema: 'SsoConnection',
    tag: 'Enterprise SSO',
    rest: ['GET', '/sso/connections/{id}'],
)]
final class ShowSsoConnection implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The connection\'s id.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $connection = SsoFields::connection($context);

        return ActionResult::item($connection, SsoFields::present($connection));
    }
}
